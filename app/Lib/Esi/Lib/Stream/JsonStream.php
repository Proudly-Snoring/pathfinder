<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 30.12.2018
 * Time: 01:09
 */

namespace Exodus4D\ESI\Lib\Stream;


use GuzzleHttp\Psr7\StreamDecoratorTrait;

class JsonStream implements JsonStreamInterface {

    use StreamDecoratorTrait;

    /**
     * @return mixed|null
     */
    public function getDecodedContents(){
        $contents = $this->getContents();

        if($contents === ''){
            return null;
        }
        $decodedContents = \GuzzleHttp\Utils::jsonDecode($contents);

        if(json_last_error() !== JSON_ERROR_NONE){
            throw new \RuntimeException('Error trying to decode response: ' . json_last_error_msg());
        }

        return $decodedContents;
    }
}