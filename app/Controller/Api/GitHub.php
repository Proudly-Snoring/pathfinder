<?php /** @noinspection PhpUndefinedMethodInspection */

/**
 * Created by PhpStorm.
 * User: exodus4d
 * Date: 16.01.16
 * Time: 03:34
 */

namespace Exodus4D\Pathfinder\Controller\Api;


use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Controller;

/**
* Github controller
* Class Route
* @package Controller\Api
*/
class GitHub extends Controller\Controller {

    /**
     * get release information from  GitHub
     * @param \Base $f3
     */
    public function releases(\Base $f3){
        $releaseCount = 4;

        $return = (object) [];
        $return->releasesData = [];
        $return->version = (object) [];
        $return->version->current =  Config::getPathfinderData('version');
        $return->version->last =  '';
        $return->version->delta = null;
        $return->version->dev = false;

        $releases = $f3->gitHubClient()->send('getProjectReleases', 'Proudly-Snoring/pathfinder', $releaseCount);

        // sanitizer for the upstream-rendered release HTML (XSS defense-in-depth)
        $purifier = $this->htmlPurifier($f3);

        foreach($releases as $key => &$release){
            // check version ------------------------------------------------------------------------------------------
            if($key === 0){
                $return->version->last = $release['name'];
                if(version_compare( $return->version->current, $return->version->last, '>')){
                    $return->version->dev = true;
                }
            }

            if(
                !$return->version->dev &&
                version_compare($release['name'], $return->version->current, '>=')
            ){
                $return->version->delta = ($key === count($releases) - 1) ? '>= ' . $key : $key;
            }

            // format body ------------------------------------------------------------------------------------
            $body = $release['body'];

            // remove "update information" from release text
            // -> keep everything until first "***" -> horizontal line
            if( ($pos = strpos($body, '***')) !== false){
                $body = substr($body, 0, $pos);
            }

            // convert list style
            $body = str_replace(' - ', '* ', $body);

            // convert Markdown to HTML -> use either gitHub API (in oder to create abs, issue links)
            // -> or F3´s markdown as fallback
            $html = $f3->gitHubClient()->send('markdownToHtml', 'Proudly-Snoring/pathfinder', $body);

            if(!empty($html)){
                $body = $html;
            }else{
                $body = \Markdown::instance()->convert(trim($body));
            }

            // strip anything but a safe allowlist of tags/attributes/URL schemes
            // -> the body is rendered unescaped client-side ({{{ }}}), so this is the XSS gate
            $release['body'] = $purifier->purify($body);
        }

        $return->releasesData = $releases;

        echo json_encode($return);
    }

    /**
     * build an HTMLPurifier instance configured for release-note HTML
     * @param \Base $f3
     * @return \HTMLPurifier
     */
    protected function htmlPurifier(\Base $f3) : \HTMLPurifier {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed',
            'p,br,hr,h1,h2,h3,h4,h5,h6,strong,b,em,i,del,blockquote,' .
            'ul,ol,li,code,pre,a[href|title],img[src|alt|title],' .
            'table,thead,tbody,tr,th,td');
        // only http/https links and images -> blocks javascript:/data: payloads
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);

        // serializer cache must be writable (tmp/ is chmod 0766 in the image)
        $cacheDir = (string)$f3->get('TEMP') . 'htmlpurifier';
        if(!is_dir($cacheDir)){
            @mkdir($cacheDir, 0775, true); // @ -> tolerate concurrent first-request creation
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        return new \HTMLPurifier($config);
    }
}