<?php
/**
 * SDE archive handling: download the CCP EVE Static Data Export (JSONL zip),
 * extract single files on demand and clean up once the import is done.
 *
 * The zip MUST land on disk: a ZIP's central directory is at the file tail, so
 * ZipArchive needs a seekable local file and cannot read a download/memory stream.
 * Download is streamed to disk (CURLOPT_FILE) so peak memory stays flat.
 */

namespace Exodus4D\Pathfinder\Lib\Sde;

class Archive {

    /**
     * SDE download URL (JSON Lines flavour, ~84 MB)
     */
    const SDE_URL           = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip';

    /**
     * local zip filename inside the sde temp dir
     */
    const ZIP_NAME          = 'sde.zip';

    /**
     * sanity gate: a complete download is ~84 MB. A truncated/aborted download
     * must NOT look complete, so anything smaller is treated as missing.
     */
    const MIN_ZIP_BYTES     = 50 * 1024 * 1024;

    /**
     * @var \Base
     */
    protected $f3;

    /**
     * absolute/relative path to the sde working dir (inside TEMP)
     * @var string
     */
    protected $dir;

    public function __construct(){
        $this->f3 = \Base::instance();
        // mirror loadCSV(): paths are relative to the app root (cwd), TEMP = 'tmp/'
        $this->dir = $this->f3->get('TEMP') . 'sde/';
        if(!is_dir($this->dir)){
            mkdir($this->dir, 0775, true);
        }
    }

    /**
     * @return string
     */
    public function getDir() : string {
        return $this->dir;
    }

    /**
     * @return string
     */
    public function getZipPath() : string {
        return $this->dir . self::ZIP_NAME;
    }

    /**
     * a download is "present" only if the file exists AND is big enough to be complete
     * @return bool
     */
    public function isDownloaded() : bool {
        $path = $this->getZipPath();
        return is_file($path) && filesize($path) >= self::MIN_ZIP_BYTES;
    }

    /**
     * stream the SDE zip to disk via curl.
     * -> writes to a *.part file, then atomically renames on success so a resume
     *    never sees a truncated file as complete.
     * @return bool true if already present or freshly downloaded
     * @throws \Exception on download/integrity failure
     */
    public function download() : bool {
        if($this->isDownloaded()){
            return true;
        }
        if(!function_exists('curl_init')){
            throw new \Exception('SDE download requires the PHP curl extension');
        }

        $partPath = $this->getZipPath() . '.part';
        $fh = fopen($partPath, 'wb');
        if(!$fh){
            throw new \Exception('Cannot open SDE download target: ' . $partPath);
        }

        $ch = curl_init(self::SDE_URL);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fh,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_FAILONERROR     => true,
            CURLOPT_CONNECTTIMEOUT  => 30,
            CURLOPT_TIMEOUT         => 300,
            CURLOPT_USERAGENT       => 'pathfinder-sde-import'
        ]);
        $ok   = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if(!$ok || !is_file($partPath) || filesize($partPath) < self::MIN_ZIP_BYTES){
            @unlink($partPath);
            throw new \Exception(sprintf('SDE download failed (HTTP %d): %s', $code, $err ?: 'incomplete file'));
        }

        rename($partPath, $this->getZipPath());
        return true;
    }

    /**
     * extract a single entry from the zip on demand.
     * -> does NOT extract all 61 files (~510 MB); only the requested .jsonl.
     * -> extraction is atomic (extract to a scratch dir, then rename into place).
     * @param string $jsonlName e.g. 'mapSolarSystems.jsonl'
     * @return string absolute/relative path to the extracted file
     * @throws \Exception
     */
    public function extract(string $jsonlName) : string {
        $target = $this->dir . $jsonlName;
        if(is_file($target)){
            return $target;
        }
        if(!$this->isDownloaded()){
            throw new \Exception('SDE zip not downloaded yet');
        }

        $scratch = $this->dir . '.extract/';
        if(!is_dir($scratch)){
            mkdir($scratch, 0775, true);
        }

        if(class_exists('ZipArchive')){
            $zip = new \ZipArchive();
            if($zip->open($this->getZipPath()) !== true){
                throw new \Exception('Cannot open SDE zip: ' . $this->getZipPath());
            }
            $extracted = $zip->extractTo($scratch, $jsonlName);
            $zip->close();
            if(!$extracted || !is_file($scratch . $jsonlName)){
                throw new \Exception('ZipArchive failed to extract ' . $jsonlName);
            }
        }else{
            // fallback for images without php7-zip: shell out to /usr/bin/unzip
            $cmd = sprintf('unzip -o -d %s %s %s 2>&1',
                escapeshellarg(rtrim($scratch, '/')),
                escapeshellarg($this->getZipPath()),
                escapeshellarg($jsonlName)
            );
            exec($cmd, $out, $rc);
            if($rc !== 0 || !is_file($scratch . $jsonlName)){
                throw new \Exception('unzip failed for ' . $jsonlName . ': ' . implode("\n", $out));
            }
        }

        rename($scratch . $jsonlName, $target);
        return $target;
    }

    /**
     * delete every extracted .jsonl and the source zip.
     * -> called once all import tasks are complete; the SDE is a transient build input.
     */
    public function cleanup() : void {
        foreach((array)glob($this->dir . '*.jsonl') as $file){
            @unlink($file);
        }
        @unlink($this->getZipPath());
        @unlink($this->getZipPath() . '.part');
        $scratch = $this->dir . '.extract/';
        if(is_dir($scratch)){
            foreach((array)glob($scratch . '*') as $file){
                @unlink($file);
            }
            @rmdir($scratch);
        }
    }
}
