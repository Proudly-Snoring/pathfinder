<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 23.02.2019
 * Time: 19:11
 */

namespace Exodus4D\Pathfinder\Lib\Logging\Handler;


use Monolog\Logger;
use Monolog\LogRecord;

class SocketHandler extends \Monolog\Handler\SocketHandler {

    /**
     * some meta data (additional processing information)
     * @var array|string
     */
    protected $metaData                 = [];

    /**
     * SocketHandler constructor.
     * @param $connectionString
     * @param int $level
     * @param bool $bubble
     * @param array $metaData
     */
    public function __construct($connectionString, $level = Logger::DEBUG, $bubble = true, $metaData = []){
        $this->metaData = $metaData;

        parent::__construct($connectionString, $level, $bubble);
    }

    /**
     * wrap the formatted record (expects the 'json' formatter) into the {task, load} envelope
     * the receiving socket service expects, alongside this handler's meta data
     * @param LogRecord $record
     * @return string
     */
    protected function generateDataStream(LogRecord $record) : string {
        return json_encode([
            'task' => 'logData',
            'load' => [
                'meta' => $this->metaData,
                'log' => json_decode((string)$record->formatted, true)
            ]
        ]);
    }
}