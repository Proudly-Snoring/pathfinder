<?php
/**
 * Streaming reader for a single extracted SDE JSON Lines file.
 *
 * - Every line is one JSON object keyed by "_key" (the entity id).
 * - Files are always read line-by-line (never loaded whole) so peak memory stays
 *   flat regardless of file size (types.jsonl alone is ~150 MB).
 * - For random single-record access (e.g. resolving a star/planet/stargate type
 *   that lives anywhere in types.jsonl) a one-time "_key -> byte offset" index is
 *   built and cached, so subsequent reads are an fseek + one fgets.
 */

namespace Exodus4D\Pathfinder\Lib\Sde;

class JsonlFile {

    /**
     * cache TTL for the per-file id list / offset index (seconds)
     */
    const CACHE_TTL = 60 * 60;

    /**
     * @var \Base
     */
    protected $f3;

    /**
     * @var string extracted file path
     */
    protected $path;

    /**
     * @var string entry name e.g. 'types.jsonl'
     */
    protected $name;

    /**
     * @var array|null in-process memo of the offset/group index (avoids re-fetching
     * + unserializing it from the filesystem cache on every read())
     */
    protected $index;

    /**
     * @var resource|null persistent read handle reused across read() calls
     */
    protected $fh;

    /**
     * @param Archive $archive
     * @param string $name e.g. 'mapSolarSystems.jsonl'
     * @throws \Exception
     */
    public function __construct(Archive $archive, string $name){
        $this->f3   = \Base::instance();
        $this->name = $name;
        $this->path = $archive->extract($name);
    }

    /**
     * @return string
     */
    public function getPath() : string {
        return $this->path;
    }

    /**
     * cache key bound to file path + mtime so a re-download invalidates it
     * @param string $kind
     * @return string
     */
    protected function cacheKey(string $kind) : string {
        return 'SDE_' . $kind . '_' . md5($this->path . '_' . (int)@filemtime($this->path));
    }

    /**
     * build (and cache) the "_key -> byte offset" index for random reads.
     * Also captures groupID per row (cheap regex) so the type import can list all
     * type ids in a group without a second scan. Non-type files just ignore groups.
     * @return array ['offsets' => [id => pos], 'groups' => [groupId => [id,...]]]
     */
    protected function buildIndex() : array {
        if($this->index !== null){
            return $this->index;
        }
        $cacheKey = $this->cacheKey('IDX');
        if($this->f3->exists($cacheKey, $index) && is_array($index)){
            return $this->index = $index;
        }

        $offsets = [];
        $groups  = [];
        $fh = fopen($this->path, 'rb');
        if($fh){
            while(true){
                $pos  = ftell($fh);
                $line = fgets($fh);
                if($line === false){
                    break;
                }
                if($line === '' || $line[0] !== '{'){
                    continue;
                }
                // "_key" is always the first key in every SDE JSONL row
                if(preg_match('/^\{"_key":\s*(\d+)/', $line, $m)){
                    $id = (int)$m[1];
                    $offsets[$id] = $pos;
                    if(preg_match('/"groupID":\s*(\d+)/', $line, $g)){
                        $groups[(int)$g[1]][] = $id;
                    }
                }
            }
            fclose($fh);
        }

        $index = ['offsets' => $offsets, 'groups' => $groups];
        $this->f3->set($cacheKey, $index, self::CACHE_TTL);
        return $this->index = $index;
    }

    /**
     * sorted ascending list of all "_key" ids in the file
     * @return array
     */
    public function ids() : array {
        $cacheKey = $this->cacheKey('IDS');
        if(!$this->f3->exists($cacheKey, $ids) || !is_array($ids)){
            $ids = array_keys($this->buildIndex()['offsets']);
            sort($ids, SORT_NUMERIC);
            $this->f3->set($cacheKey, $ids, self::CACHE_TTL);
        }
        return $ids;
    }

    /**
     * all type ids that belong to a given groupID (types.jsonl only)
     * @param int $groupId
     * @return array
     */
    public function idsByGroup(int $groupId) : array {
        $groups = $this->buildIndex()['groups'];
        $ids = $groups[$groupId] ?? [];
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * read a single row by id (fseek + one line). Returns the decoded assoc array.
     * @param int $id
     * @return array|null
     */
    public function read(int $id) : ?array {
        $offsets = $this->buildIndex()['offsets'];
        if(!isset($offsets[$id])){
            return null;
        }
        if(!$this->fh){
            $this->fh = fopen($this->path, 'rb') ?: null;
            if(!$this->fh){
                return null;
            }
        }
        fseek($this->fh, $offsets[$id]);
        $line = fgets($this->fh);

        $row = $line === false ? null : json_decode($line, true);
        return is_array($row) ? $row : null;
    }

    public function __destruct(){
        if($this->fh){
            fclose($this->fh);
            $this->fh = null;
        }
    }

    /**
     * stream every row through a callback (no whole-file load)
     * @param callable $callback function(array $row) : void
     */
    public function each(callable $callback) : void {
        $fh = fopen($this->path, 'rb');
        if(!$fh){
            return;
        }
        while(($line = fgets($fh)) !== false){
            if($line === '' || $line[0] !== '{'){
                continue;
            }
            $row = json_decode($line, true);
            if(is_array($row)){
                $callback($row);
            }
        }
        fclose($fh);
    }
}
