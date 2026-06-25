<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 19.01.2019
 * Time: 05:16
 */

namespace Exodus4D\ESI\Lib\Stream;


use Psr\Http\Message\StreamInterface;

interface JsonStreamInterface extends StreamInterface {

    /**
     * Returns the remaining contents JSON-decoded
     * -> NOT named getContents(): StreamInterface::getContents() is typed ": string" since
     *    psr/http-message 2.0, so a decoded (non-string) return type there would violate LSP.
     *
     * @return mixed
     * @throws \RuntimeException if unable to read, decode, or an error occurs while
     *     reading.
     */
    public function getDecodedContents();
}