<?php


namespace Exodus4D\ESI\Mapper\EveScout;

use Exodus4D\Pathfinder\Data\Mapper\AbstractIterator;

class Connection extends AbstractIterator {

    /**
     * @var array
     */
    protected static $map = [
        'id'                                => 'id',
        'signature_type'                    => 'type',

        'completed'                         => ['state' => 'name'],
        'updated_at'                        => ['state' => 'updated'],

        'out_system_id'                     => ['source' => 'id'],
        'out_system_name'                   => ['source' => 'name'],

        'in_system_id'                     => ['target' => 'id'],
        'in_system_name'                   => ['target' => 'name'],
        'in_system_class'                  => ['target' => 'system_class'],

        'out_signature'                     => ['sourceSignature' => 'name'],
        'in_signature'                      => ['targetSignature' => 'name'],

        'remaining_hours'                   => ['wormhole' => 'estimatedEol'],
        'max_ship_size'                     => ['wormhole' => 'jumpMass'],

        'created_at'                        => 'created',
        'updated_at'                        => 'updated',
        
        'wh_exits_outward'                  => 'wh_exits_outward',
        'wh_type'                           => 'wh_type',

        'created_by_id'                     => ['character' => 'id'],
        'created_by_name'                   => ['character' => 'name']
    ];
}