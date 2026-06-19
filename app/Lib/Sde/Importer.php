<?php
/**
 * SDE -> Pathfinder importer.
 *
 * Builds an ESI-shaped array from each SDE JSONL row and feeds it through the
 * existing Cortex models (copyfrom + save), so all field derivation, validation
 * and relations stay in the models. No business logic is re-implemented here.
 *
 * Relations are wired exactly like each model's loadData(), except ESI's
 * loadById() is swapped for getById() (DB-only, no network): the FK import order
 * (region -> constellation -> system ...) guarantees the related row already
 * exists, and the models' `validate => notDry` fails loudly if that order is
 * ever violated.
 */

namespace Exodus4D\Pathfinder\Lib\Sde;

use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Model\Universe\AbstractUniverseModel;
use Exodus4D\Pathfinder\Model\Universe\TypeModel;

class Importer {

    /**
     * wormhole effect map: mapSecondarySuns typeID -> Pathfinder effect code.
     * VERIFIED June 2026 against the legacy dump (exact 1:1, per-effect counts match).
     */
    const EFFECT_BY_TYPE = [
        30574 => 'magnetar',
        30575 => 'blackHole',
        30576 => 'redGiant',
        30577 => 'pulsar',
        30669 => 'wolfRayet',
        30670 => 'cataclysmic'
    ];

    /**
     * @var Archive
     */
    protected $archive;

    /**
     * @var JsonlFile[] lazily-opened file readers, keyed by entry name
     */
    protected $files = [];

    /**
     * @var array solarSystemID -> effect code (built once from mapSecondarySuns)
     */
    protected $effectMap;

    /**
     * @var array ids already ensured this run, per kind, to skip re-checking
     */
    protected $ensured = ['type' => [], 'group' => [], 'category' => [], 'dogma' => []];

    /**
     * @var array categoryID -> [groupId,...] (built once from groups.jsonl)
     */
    protected $groupsByCategory;

    /**
     * @var array constellationID -> wormholeClassID (built once)
     */
    protected $constClass;

    /**
     * @var array regionID -> wormholeClassID (built once)
     */
    protected $regionClass;

    /**
     * @var \Exodus4D\Pathfinder\Model\Universe\TypeModel[] valid type models cached
     * by id, so a star/planet/stargate type (the same ~handful repeated across
     * thousands of celestials) is SELECTed at most once per run instead of on
     * every celestial. This is the dominant per-system round-trip saving.
     */
    protected $typeCache = [];

    /**
     * @param Archive|null $archive
     */
    public function __construct(?Archive $archive = null){
        $this->archive = $archive ?: new Archive();
    }

    /**
     * @return Archive
     */
    public function getArchive() : Archive {
        return $this->archive;
    }

    /**
     * lazily open (extract + reader) an SDE file
     * @param string $name
     * @return JsonlFile
     * @throws \Exception
     */
    protected function file(string $name) : JsonlFile {
        if(!isset($this->files[$name])){
            $this->files[$name] = new JsonlFile($this->archive, $name);
        }
        return $this->files[$name];
    }

    /**
     * Run $fn inside a single DB transaction on the UNIVERSE connection (the same
     * connection the Cortex models save through). Collapses the per-row autocommit
     * fsyncs of a whole chunk into one commit -> the import is no longer disk-sync
     * bound. Rolls back on error so a failed chunk leaves no partial rows.
     * @param callable $fn
     * @return mixed $fn's return value
     * @throws \Throwable
     */
    protected function transactional(callable $fn) {
        $db = \Base::instance()->DB->getDB(AbstractUniverseModel::DB_ALIAS);
        $db->begin();
        try {
            $result = $fn();
            $db->commit();
            return $result;
        } catch(\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * pick the English value from a localized SDE object ({en, de, ...})
     * @param mixed $value
     * @return string
     */
    protected function en($value) : string {
        if(is_array($value)){
            return (string)($value['en'] ?? reset($value) ?? '');
        }
        return (string)$value;
    }

    // -- region ------------------------------------------------------------------

    /**
     * import ALL regions (small file; run eagerly, idempotent)
     * @return int rows processed
     * @throws \Exception
     */
    public function importRegions() : int {
        /**
         * @var $model \Exodus4D\Pathfinder\Model\Universe\RegionModel
         */
        $model = AbstractUniverseModel::getNew('RegionModel');
        $count = 0;
        $en = function($v){ return $this->en($v); };

        $this->file('mapRegions.jsonl')->each(function(array $row) use ($model, $en, &$count){
            $data = [
                'id'            => (int)$row['_key'],
                'name'          => $en($row['name'] ?? ''),
                'description'   => $en($row['description'] ?? '')
            ];
            $model->getById($data['id'], 0);
            $model->copyfrom($data, ['id', 'name', 'description']);
            $model->save();
            $model->reset();
            $count++;
        });

        return $count;
    }

    // -- constellation -----------------------------------------------------------

    /**
     * import ALL constellations (small file; run eagerly, idempotent).
     * Regions must be imported first.
     * @return int rows processed
     * @throws \Exception
     */
    public function importConstellations() : int {
        /**
         * @var $model \Exodus4D\Pathfinder\Model\Universe\ConstellationModel
         */
        $model = AbstractUniverseModel::getNew('ConstellationModel');
        $count = 0;
        $en = function($v){ return $this->en($v); };

        $this->file('mapConstellations.jsonl')->each(function(array $row) use ($model, $en, &$count){
            $region = $model->rel('regionId');
            $region->getById((int)$row['regionID'], 0);

            $data = [
                'id'        => (int)$row['_key'],
                'name'      => $en($row['name'] ?? ''),
                'regionId'  => $region,
                'position'  => $row['position'] ?? null
            ];
            $model->getById($data['id'], 0);
            $model->copyfrom($data, ['id', 'name', 'regionId', 'position']);
            $model->save();
            $model->reset();
            $count++;
        });

        return $count;
    }

    // -- type / group / category resolution --------------------------------------

    /**
     * ensure a category row exists (read from categories.jsonl if missing)
     * @param int $id
     * @throws \Exception
     */
    protected function ensureCategory(int $id) : void {
        if($id <= 0 || isset($this->ensured['category'][$id])){
            return;
        }
        $model = AbstractUniverseModel::getNew('CategoryModel');
        $model->getById($id, 0);
        if($model->dry() && ($row = $this->file('categories.jsonl')->read($id))){
            $model->copyfrom([
                'id'        => $id,
                'name'      => $this->en($row['name'] ?? ''),
                'published' => (bool)($row['published'] ?? false)
            ], ['id', 'name', 'published']);
            $model->save();
        }
        $this->ensured['category'][$id] = true;
    }

    /**
     * ensure a group row exists (read from groups.jsonl if missing)
     * @param int $id
     * @throws \Exception
     */
    protected function ensureGroup(int $id) : void {
        if($id <= 0 || isset($this->ensured['group'][$id])){
            return;
        }
        $model = AbstractUniverseModel::getNew('GroupModel');
        $model->getById($id, 0);
        if($model->dry() && ($row = $this->file('groups.jsonl')->read($id))){
            $categoryId = (int)($row['categoryID'] ?? 0);
            $this->ensureCategory($categoryId);
            $category = $model->rel('categoryId');
            $category->getById($categoryId, 0);
            $model->copyfrom([
                'id'         => $id,
                'name'       => $this->en($row['name'] ?? ''),
                'published'  => (bool)($row['published'] ?? false),
                'categoryId' => $category
            ], ['id', 'name', 'published', 'categoryId']);
            $model->save();
        }
        $this->ensured['group'][$id] = true;
    }

    /**
     * build an ESI-shaped type array from an SDE types.jsonl row (camelCase remap)
     * @param array $row
     * @return array
     */
    protected function typeData(array $row) : array {
        return [
            'id'                => (int)$row['_key'],
            'name'              => $this->en($row['name'] ?? ''),
            'description'       => $this->en($row['description'] ?? ''),
            'published'         => (bool)($row['published'] ?? false),
            'radius'            => (float)($row['radius'] ?? 0),
            'volume'            => (float)($row['volume'] ?? 0),
            'capacity'          => (float)($row['capacity'] ?? 0),
            'mass'              => (float)($row['mass'] ?? 0),
            'marketGroupId'     => (int)($row['marketGroupID'] ?? 0),
            'packagedVolume'    => (float)($row['packagedVolume'] ?? 0),
            'portionSize'       => (int)($row['portionSize'] ?? 0),
            'graphicId'         => (int)($row['graphicID'] ?? 0)
        ];
    }

    /**
     * ensure a type row exists (read from types.jsonl if missing), resolving its
     * group + category. Mirrors the lazy ESI creation of celestial types (suns,
     * planets, stargates) that no import button covers.
     * @param int $id
     * @return \Exodus4D\Pathfinder\Model\Universe\TypeModel valid model, or dry if id unknown
     * @throws \Exception
     */
    protected function ensureType(int $id) : \Exodus4D\Pathfinder\Model\Universe\TypeModel {
        if(isset($this->typeCache[$id])){
            return $this->typeCache[$id];
        }
        /**
         * @var $model \Exodus4D\Pathfinder\Model\Universe\TypeModel
         */
        $model = AbstractUniverseModel::getNew('TypeModel');
        $model->getById($id, 0);
        if($model->dry() && ($row = $this->file('types.jsonl')->read($id))){
            $groupId = (int)($row['groupID'] ?? 0);
            $this->ensureGroup($groupId);
            $group = $model->rel('groupId');
            $group->getById($groupId, 0);

            $data = $this->typeData($row);
            $data['groupId'] = $group;
            $model->copyfrom($data, [
                'id', 'name', 'description', 'published', 'radius', 'volume', 'capacity',
                'mass', 'groupId', 'marketGroupId', 'packagedVolume', 'portionSize', 'graphicId'
            ]);
            $model->save();
            $this->ensured['type'][$id] = true;
        }
        if(!$model->dry()){
            $this->typeCache[$id] = $model;
        }
        return $model;
    }

    /**
     * ensure a dogma_attribute row exists (read from dogmaAttributes.jsonl if missing),
     * so TypeModel::syncDogmaAttributes' loadById() finds it in DB and never hits ESI.
     * @param int $id
     * @throws \Exception
     */
    protected function ensureDogmaAttribute(int $id) : void {
        if($id <= 0 || isset($this->ensured['dogma'][$id])){
            return;
        }
        $model = AbstractUniverseModel::getNew('DogmaAttributeModel');
        $model->getById($id, 0);
        if($model->dry() && ($row = $this->file('dogmaAttributes.jsonl')->read($id))){
            $model->copyfrom([
                'id'            => $id,
                'name'          => (string)($row['name'] ?? ''),          // plain string
                'displayName'   => isset($row['displayName']) ? $this->en($row['displayName']) : null,
                'description'   => (string)($row['description'] ?? ''),    // plain string
                'published'     => isset($row['published']) ? (bool)$row['published'] : null,
                'stackable'     => isset($row['stackable']) ? (bool)$row['stackable'] : null,
                'highIsGood'    => isset($row['highIsGood']) ? (bool)$row['highIsGood'] : null,
                'defaultValue'  => (float)($row['defaultValue'] ?? 0),
                'iconId'        => isset($row['iconID']) ? (int)$row['iconID'] : null,
                'unitId'        => isset($row['unitID']) ? (int)$row['unitID'] : null
            ], ['id', 'name', 'displayName', 'description', 'published', 'stackable', 'highIsGood', 'defaultValue', 'iconId', 'unitId']);
            $model->save();
        }
        $this->ensured['dogma'][$id] = true;
    }

    /**
     * build the ESI-shaped 'dogma_attributes' list for a type from typeDogma.jsonl,
     * ensuring each referenced dogma_attribute row exists first.
     * @param int $typeId
     * @return array [['attributeId'=>int,'value'=>float], ...]
     * @throws \Exception
     */
    protected function dogmaFor(int $typeId) : array {
        $row = $this->file('typeDogma.jsonl')->read($typeId);
        $out = [];
        foreach((array)($row['dogmaAttributes'] ?? []) as $attr){
            $attributeId = (int)($attr['attributeID'] ?? 0);
            if($attributeId){
                $this->ensureDogmaAttribute($attributeId);
                $out[] = ['attributeId' => $attributeId, 'value' => $attr['value'] ?? 0];
            }
        }
        return $out;
    }

    /**
     * import a single type WITH its dogma attributes (typeDogma + wormhole.csv overlay).
     * Mirrors TypeModel::loadData + manipulateDogmaAttributes, SDE-sourced.
     * @param int $id
     * @param bool $storeDogma
     * @throws \Exception
     */
    protected function saveType(int $id, bool $storeDogma) : void {
        $row = $this->file('types.jsonl')->read($id);
        if(!$row){
            return;
        }
        $groupId = (int)($row['groupID'] ?? 0);
        $this->ensureGroup($groupId);

        /**
         * @var $model TypeModel
         */
        $model = AbstractUniverseModel::getNew('TypeModel');
        $model->storeDogmaAttributes = $storeDogma;
        $model->getById($id, 0);
        $group = $model->rel('groupId');
        $group->getById($groupId, 0);

        $data = $this->typeData($row);
        $data['groupId'] = $group;
        $whitelist = [
            'id', 'name', 'description', 'published', 'radius', 'volume', 'capacity',
            'mass', 'groupId', 'marketGroupId', 'packagedVolume', 'portionSize', 'graphicId'
        ];

        if($storeDogma){
            $dogma = $this->dogmaFor($id);
            // wormhole.csv overlay (mirror TypeModel::manipulateDogmaAttributes):
            // scanWormholeStrength (attr 1908) is curated, not in the SDE typeDogma.
            if($groupId === Config::ESI_GROUP_WORMHOLE_ID){
                $csv = TypeModel::getCSVData('wormhole', 'name');
                $whName = TypeModel::formatWormholeName($data['name']);
                if($whName && !empty($csv[$whName]) && !empty($strength = (float)$csv[$whName]['scanWormholeStrength'])){
                    $this->ensureDogmaAttribute(Config::ESI_DOGMA_ATTRIBUTE_SCANWHSTRENGTH_ID);
                    $dogma[] = [
                        'attributeId'   => Config::ESI_DOGMA_ATTRIBUTE_SCANWHSTRENGTH_ID,
                        'value'         => $strength
                    ];
                }
            }
            $data['dogma_attributes'] = $dogma;
            $whitelist[] = 'dogma_attributes';
        }

        $model->copyfrom($data, $whitelist);
        $model->save();
        $model->reset();
    }

    /**
     * categoryID -> [groupId,...] lookup (built once from groups.jsonl)
     * @param int $categoryId
     * @return array
     * @throws \Exception
     */
    protected function groupsForCategory(int $categoryId) : array {
        if($this->groupsByCategory === null){
            $this->groupsByCategory = [];
            $this->file('groups.jsonl')->each(function(array $row){
                $cid = (int)($row['categoryID'] ?? 0);
                $this->groupsByCategory[$cid][] = (int)$row['_key'];
            });
        }
        return $this->groupsByCategory[$categoryId] ?? [];
    }

    /**
     * chunked import of all types in a single group (e.g. Wormholes group 988).
     * @param int $groupId
     * @param int $offset
     * @param int $length
     * @param bool $storeDogma
     * @return array ['countAll','count','offset']
     * @throws \Exception
     */
    public function importTypesByGroup(int $groupId, int $offset = 0, int $length = 0, bool $storeDogma = false) : array {
        $info = ['countAll' => 0, 'count' => 0, 'offset' => $offset];

        $ids = $this->file('types.jsonl')->idsByGroup($groupId);
        $info['countAll'] = count($ids);

        $slice = $length ? array_slice($ids, $offset, $length) : $ids;
        $this->transactional(function() use ($slice, $storeDogma, &$info){
            foreach($slice as $id){
                $this->saveType((int)$id, $storeDogma);
                $info['count']++;
                $info['offset']++;
            }
        });

        return $info;
    }

    /**
     * chunked import of all types under a category (e.g. Structures cat 65, Ships cat 6),
     * across every group in that category. Mirrors the ESI category->group->type subset.
     * @param int $categoryId
     * @param int $offset
     * @param int $length
     * @param bool $storeDogma
     * @return array ['countAll','count','offset']
     * @throws \Exception
     */
    public function importTypesByCategory(int $categoryId, int $offset = 0, int $length = 0, bool $storeDogma = false) : array {
        $info = ['countAll' => 0, 'count' => 0, 'offset' => $offset];

        $file = $this->file('types.jsonl');
        $ids = [];
        foreach($this->groupsForCategory($categoryId) as $groupId){
            foreach($file->idsByGroup($groupId) as $typeId){
                $ids[] = $typeId;
            }
        }
        sort($ids, SORT_NUMERIC);
        $info['countAll'] = count($ids);

        $slice = $length ? array_slice($ids, $offset, $length) : $ids;
        $this->transactional(function() use ($slice, $storeDogma, &$info){
            foreach($slice as $id){
                $this->saveType((int)$id, $storeDogma);
                $info['count']++;
                $info['offset']++;
            }
        });

        return $info;
    }

    // -- effect map --------------------------------------------------------------

    /**
     * solarSystemID -> wormhole effect code, built once from mapSecondarySuns.jsonl
     * @return array
     * @throws \Exception
     */
    protected function effectMap() : array {
        if($this->effectMap === null){
            $this->effectMap = [];
            $this->file('mapSecondarySuns.jsonl')->each(function(array $row){
                $typeId = (int)($row['typeID'] ?? 0);
                $systemId = (int)($row['solarSystemID'] ?? 0);
                if($systemId && isset(self::EFFECT_BY_TYPE[$typeId])){
                    $this->effectMap[$systemId] = self::EFFECT_BY_TYPE[$typeId];
                }
            });
        }
        return $this->effectMap;
    }

    // -- wormhole class ----------------------------------------------------------

    /**
     * Build the constellation/region wormholeClassID lookups once.
     * In the SDE the WH class lives at constellation/region level (only the 5
     * Drifter systems carry it per-system). VERIFIED June 2026 against the legacy
     * dump: system -> constellation -> region derivation reproduces all 2604 WH
     * systems' security exactly.
     * @throws \Exception
     */
    protected function loadWhClassMaps() : void {
        if($this->constClass !== null){
            return;
        }
        $this->constClass = [];
        $this->regionClass = [];
        $this->file('mapConstellations.jsonl')->each(function(array $row){
            if(isset($row['wormholeClassID'])){
                $this->constClass[(int)$row['_key']] = (int)$row['wormholeClassID'];
            }
        });
        $this->file('mapRegions.jsonl')->each(function(array $row){
            if(isset($row['wormholeClassID'])){
                $this->regionClass[(int)$row['_key']] = (int)$row['wormholeClassID'];
            }
        });
    }

    /**
     * resolve a system's wormhole class: system-level -> constellation -> region
     * @param array $row mapSolarSystems row
     * @return int|null
     * @throws \Exception
     */
    protected function wormholeClassId(array $row) : ?int {
        if(isset($row['wormholeClassID'])){
            return (int)$row['wormholeClassID'];
        }
        $this->loadWhClassMaps();
        $constId = (int)($row['constellationID'] ?? 0);
        if(isset($this->constClass[$constId])){
            return $this->constClass[$constId];
        }
        $regionId = (int)($row['regionID'] ?? 0);
        return $this->regionClass[$regionId] ?? null;
    }

    // -- star --------------------------------------------------------------------

    /**
     * import one star (SDE has no star name -> synthesize "<system> - Star" like ESI)
     * @param int $starId
     * @param string $systemName
     * @throws \Exception
     */
    protected function importStar(int $starId, string $systemName) : void {
        $row = $this->file('mapStars.jsonl')->read($starId);
        if(!$row){
            return;
        }
        $type = $this->ensureType((int)($row['typeID'] ?? 0));
        if($type->dry()){
            return;
        }
        $stats = (array)($row['statistics'] ?? []);

        /**
         * @var $model \Exodus4D\Pathfinder\Model\Universe\StarModel
         */
        $model = AbstractUniverseModel::getNew('StarModel');
        $model->getById($starId, 0);
        $model->copyfrom([
            'id'            => $starId,
            'name'          => $systemName . ' - Star',
            'typeId'        => $type,
            'age'           => isset($stats['age']) ? (int)$stats['age'] : null,
            'radius'        => isset($row['radius']) ? (int)$row['radius'] : null,
            'temperature'   => isset($stats['temperature']) ? (int)$stats['temperature'] : null,
            'luminosity'    => isset($stats['luminosity']) ? (float)$stats['luminosity'] : null,
            'spectralClass' => isset($stats['spectralClass']) ? (string)$stats['spectralClass'] : null
        ], ['id', 'name', 'typeId', 'age', 'radius', 'temperature', 'luminosity', 'spectralClass']);
        $model->save();
        $model->reset();
    }

    // -- planet ------------------------------------------------------------------

    /**
     * roman numeral for a planet's celestialIndex (ESI names planets "<system> <roman>")
     * @param int $n
     * @return string
     */
    protected function roman(int $n) : string {
        $map = [1000=>'M',900=>'CM',500=>'D',400=>'CD',100=>'C',90=>'XC',50=>'L',40=>'XL',10=>'X',9=>'IX',5=>'V',4=>'IV',1=>'I'];
        $out = '';
        foreach($map as $value => $symbol){
            while($n >= $value){
                $out .= $symbol;
                $n -= $value;
            }
        }
        return $out;
    }

    /**
     * import one planet (SDE has no planet name -> synthesize from system + celestialIndex)
     * @param int $planetId
     * @param \Exodus4D\Pathfinder\Model\Universe\SystemModel $system valid system model (FK)
     * @param string $systemName
     * @throws \Exception
     */
    protected function importPlanet(int $planetId, $system, string $systemName) : void {
        $row = $this->file('mapPlanets.jsonl')->read($planetId);
        if(!$row){
            return;
        }
        $type = $this->ensureType((int)($row['typeID'] ?? 0));
        if($type->dry()){
            return;
        }
        $index = (int)($row['celestialIndex'] ?? 0);

        /**
         * @var $model \Exodus4D\Pathfinder\Model\Universe\PlanetModel
         */
        $model = AbstractUniverseModel::getNew('PlanetModel');
        $model->getById($planetId, 0);
        $model->copyfrom([
            'id'        => $planetId,
            'name'      => $index ? ($systemName . ' ' . $this->roman($index)) : $systemName,
            'systemId'  => $system,
            'typeId'    => $type,
            'position'  => $row['position'] ?? null
        ], ['id', 'name', 'systemId', 'typeId', 'position']);
        $model->save();
        $model->reset();
    }

    // -- system ------------------------------------------------------------------

    /**
     * import one solar system (+ its star + planets), deriving WH security/effect.
     * Constellations (and regions) must already exist.
     * @param array $row mapSolarSystems row
     * @throws \Exception
     */
    protected function importSystem(array $row) : void {
        $id   = (int)$row['_key'];
        $name = $this->en($row['name'] ?? '');

        /**
         * @var $system \Exodus4D\Pathfinder\Model\Universe\SystemModel
         */
        $system = AbstractUniverseModel::getNew('SystemModel');

        $constellation = $system->rel('constellationId');
        $constellation->getById((int)$row['constellationID'], 0);

        $data = [
            'id'                => $id,
            'name'              => $name,
            'constellationId'   => $constellation,
            'securityStatus'    => (float)($row['securityStatus'] ?? 0),
            'securityClass'     => $row['securityClass'] ?? null,
            'position'          => $row['position'] ?? null
        ];

        // star (optional; e.g. some special systems have none)
        if(!empty($row['starID'])){
            $this->importStar((int)$row['starID'], $name);
            $star = $system->rel('starId');
            $star->getById((int)$row['starID'], 0);
            if(!$star->dry()){
                $data['starId'] = $star;
            }
        }

        $system->getById($id, 0);
        $system->copyfrom($data, ['id', 'name', 'constellationId', 'starId', 'securityStatus', 'securityClass', 'position']);

        // WH security: set_securityStatus deliberately skips J-systems & Thera. Derive the
        // class (system -> constellation -> region) and override ONLY for true WH C-classes
        // (1-6, 12-18). This leaves k-space (class 7/8/9 -> H/L/0.0) and Abyssal/Pochven to
        // set_securityStatus, which handles their nuances (Pocket 'P', Abyssal 'A').
        $classId = $this->wormholeClassId($row);
        if($classId !== null){
            $security = AbstractUniverseModel::getSystemSecurityFromId($classId);
            if($security !== null && $security[0] === 'C'){
                $system->security = $security;
            }
        }

        // WH effect (NULL for k-space) from the mapSecondarySuns typeID map
        $system->effect = $this->effectMap()[$id] ?? null;

        $system->save();

        // planets
        foreach((array)($row['planetIDs'] ?? []) as $planetId){
            $this->importPlanet((int)$planetId, $system, $name);
        }
    }

    // -- stargate ----------------------------------------------------------------

    /**
     * import one stargate from a mapStargates row.
     * Mirrors StargateModel::loadData: origin + destination systems must already
     * exist (getById, never created here); a stargate whose destination system is
     * not imported is skipped (same as the ESI flow). SDE has no stargate name, so
     * synthesize ESI's "Stargate (<destination system>)" form.
     * @param array $row
     * @param \Exodus4D\Pathfinder\Model\Universe\StargateModel $model
     * @throws \Exception
     */
    protected function importStargate(array $row, $model) : void {
        $originId = (int)($row['solarSystemID'] ?? 0);
        $destId   = (int)($row['destination']['solarSystemID'] ?? 0);

        $system = $model->rel('systemId');
        $system->getById($originId, 0);
        if($system->dry()){
            return;
        }
        $dest = $model->rel('destinationSystemId');
        $dest->getById($destId, 0);
        if($dest->dry()){
            return;
        }
        $type = $this->ensureType((int)($row['typeID'] ?? 0));
        if($type->dry()){
            return;
        }

        $model->getById((int)$row['_key'], 0);
        $model->copyfrom([
            'id'                    => (int)$row['_key'],
            'name'                  => 'Stargate (' . $dest->name . ')',
            'systemId'              => $system,
            'typeId'                => $type,
            'destinationSystemId'   => $dest,
            'position'              => $row['position'] ?? null
        ], ['id', 'name', 'systemId', 'typeId', 'destinationSystemId', 'position']);
        $model->save();
        $model->reset();
    }

    /**
     * chunked stargate import for the /setup ajax loop. Systems must exist first.
     * @param int $offset
     * @param int $length 0 -> all
     * @return array ['countAll','count','offset']
     * @throws \Exception
     */
    public function importStargates(int $offset = 0, int $length = 0) : array {
        $info = ['countAll' => 0, 'count' => 0, 'offset' => $offset];

        $file = $this->file('mapStargates.jsonl');
        $ids = $file->ids();
        $info['countAll'] = count($ids);

        /**
         * @var $model \Exodus4D\Pathfinder\Model\Universe\StargateModel
         */
        $model = AbstractUniverseModel::getNew('StargateModel');

        $slice = $length ? array_slice($ids, $offset, $length) : $ids;
        $this->transactional(function() use ($slice, $file, $model, &$info){
            foreach($slice as $id){
                if($row = $file->read((int)$id)){
                    $this->importStargate($row, $model);
                }
                $info['count']++;
                $info['offset']++;
            }
        });

        return $info;
    }

    /**
     * chunked system import for the /setup ajax loop.
     * At offset 0 the (small) region + constellation files are imported eagerly so
     * each system's constellation FK resolves.
     * @param int $offset
     * @param int $length 0 -> all
     * @return array ['countAll','count','offset']
     * @throws \Exception
     */
    public function importSystems(int $offset = 0, int $length = 0) : array {
        $info = ['countAll' => 0, 'count' => 0, 'offset' => $offset];

        $file = $this->file('mapSolarSystems.jsonl');
        $ids = $file->ids();
        $info['countAll'] = count($ids);

        $slice = $length ? array_slice($ids, $offset, $length) : $ids;
        $this->transactional(function() use ($offset, $slice, $file, &$info){
            if($offset === 0){
                $this->importRegions();
                $this->importConstellations();
            }
            foreach($slice as $id){
                if($row = $file->read((int)$id)){
                    $this->importSystem($row);
                }
                $info['count']++;
                $info['offset']++;
            }
        });

        return $info;
    }
}
