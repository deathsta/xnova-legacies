<?php

/**
 *
 * @uses Legacies_Object
 * @uses Legacies_Empire
 * @uses Legacies_Empire_User
 */
class Legacies_Empire_Model_Planet
    extends Legacies_Core_Entity
{
    const TYPE_PLANET = 1;
    const TYPE_DEBRIS = 2;
    const TYPE_MOON   = 3;

    protected $_user = null;
    protected $_moon = null;
    protected $_now = null;

    protected static $_instances = array();

    protected static $_productionCodes = array(
        Legacies_Empire::ID_BUILDING_METAL_MINE            => 'metal_mine',
        Legacies_Empire::ID_BUILDING_CRISTAL_MINE          => 'cristal_mine',
        Legacies_Empire::ID_BUILDING_DEUTERIUM_SYNTHETISER => 'deuterium_synthetiser',
        Legacies_Empire::ID_BUILDING_SOLAR_PLANT           => 'solar_plant',
        Legacies_Empire::ID_BUILDING_FUSION_REACTOR        => 'fusion_reactor',
        Legacies_Empire::ID_SHIP_SOLAR_SATELLITE           => 'solar_satelite'
        );

    protected static $_productionInstances = array();

    public static function factory($id)
    {
        if ($id === null) {
            return new self();
        }

        $id = intval($id);
        if (!isset(self::$_instances[$id])) {
            $instance = new self();
            $params = func_get_args();
            call_user_func_array(array($instance, 'load'), $params);
            self::$_instances[$id] = $instance;
        }
        return self::$_instances[$id];
    }

    public function _init()
    {
        $this->_now = time();
        $this->_tableName = 'planets';
        $this->_idFieldName = 'id';
    }

    /**
     * @deprecated
     */
    protected function _now()
    {
        return $this->_now;
    }

    public function updateStorages($time = null)
    {
        Math::setPrecision(10);
        $resources = Legacies_Empire_Model_Game_Resources::getSingleton();
        foreach ($resources->getAllDatas() as $resource => $resourceData) {
            if (!isset($resourceData['storage_field']) || $resourceData['storage_field'] === null && $this['rpg_stockeur'] === null) {
                continue;
            }

            Math::setPrecision(1);
            $officerEnhancement = Math::add(Math::mul(.5, $this->getData('rpg_stockeur'), 50), 1, 10, 50);

            Math::setPrecision(0);
            $storageEnhancementFactor = Math::pow(1.6, $this[Legacies_Empire::getFieldName($resourceData['storage'])]);
            $storageEnhancement = Math::mul(BASE_STORAGE_SIZE / 2, $storageEnhancementFactor);

            $value = Math::mul(MAX_OVERFLOW, Math::mul($officerEnhancement, Math::add(BASE_STORAGE_SIZE, $storageEnhancement)));
            $this->setData($resourceData['storage_field'], $value);
        }
        Math::setPrecision();
    }

    public function updateResources($time = null)
    {
        $types = Legacies_Empire_Model_Game_Types::getSingleton();
        $resources = Legacies_Empire_Model_Game_Resources::getSingleton();
        $production = Legacies_Empire_Model_Game_Production::getSingleton();

        if ($time === null) {
            $time = $this->_now();
        }

        if ($this->getData('planet_type') != 1) {
            return $this;
        }

        $resourcesProductions = array();
        foreach ($resources->getAllDatas() as $resource => $resourceData) {
            foreach ($resourceData['production'] as $productionUnit => $ratioField) {
                if (!in_array($productionUnit, $types['prod'])) {
                    continue;
                }

                $level = $this->getData(Legacies_Empire::getFieldName($productionUnit));
                $ratio = $this->getData($ratioField);
                $element = self::getProducitonElementInstance($productionUnit);

                foreach ($element->getProductionRatios($level, $ratio, $this, $this->getUser()) as $resourceId => $resourceProduction) {
                    if (!isset($resourcesProductions[$resourceId])) {
                        $resourcesProductions[$resourceId] = $resourceProduction;
                    } else {
                        $resourcesProductions[$resourceId] = Math::add($resourcesProductions[$resourceId], $resourceProduction);
                    }
                }
            }
        }
        var_dump($resourcesProductions);

        $timeDiff = ($time - $this->getData('last_update')) / 3600;
        foreach ($resourcesProductions as $resource => $productionPerHour) {
            if (!isset($resources[$resource])) {
                continue;
            }
            $this->setData($resources[$resource]['production_field'], $productionPerHour);

            $production = Math::add($this->getData($resources[$resource]['field']), Math::mul($timeDiff, $productionPerHour));

            if (Math::comp($production, $this->getData($resources[$resource]['storage_field'])) > 0) {
                $production = $this->getData($resources[$resource]['storage_field']);
            }
            $this->setData($resources[$resource]['field'], $production);
        }

        return $this;
    }

    public static function getProducitonElementInstance($buildingId)
    {
        $production = Legacies_Empire_Model_Game_Production::getSingleton();

        if (!isset(self::$_productionInstances[$buildingId])) {
            if (!isset($production[$buildingId])) {
                return null;
            }
            $class = $production[$buildingId][Legacies_Empire::RESOURCE_CLASS];
            $reflection = new ReflectionClass($class);
            self::$_productionInstances[$buildingId] = $reflection->newInstance();
        }

        return self::$_productionInstances[$buildingId];
    }

    public function getUser()
    {
        if ($this->_user === null) {
            $this->_user = Legacies_Empire_Model_User::factory($this->getData('id_owner'));
        }
        return $this->_user;
    }

    public function isPlanet()
    {
        return (bool) ($this->getData('planet_type') == self::TYPE_PLANET);
    }

    public function isDebris()
    {
        return (bool) ($this->getData('planet_type') == self::TYPE_DEBRIS);
    }

    public function isMoon()
    {
        return (bool) ($this->getData('planet_type') == self::TYPE_MOON);
    }

    public function getMoon()
    {
        static $statement = null;

        if ($this->isMoon()) {
            return null;
        }

        if ($this->_moon === null) {
            if ($statement === null) {
                $statement = new Legacies_Core_Collection(array('planet' => 'planets'), get_class($this));
                $statement
                    ->where('galaxy=:galaxy')
                    ->where('system=:system')
                    ->where('planet=:position')
                    ->where('planet_type=' . strval(self::TYPE_MOON))
                ;
            }
            $statement->load(array(
                'galaxy' => $this->getGalaxy(),
                'system' => $this->getSystem(),
                'position' => $this->getPosition()
                ));

            $this->_moon = $statement->current();
        }
        return $this->_moon;
    }

    public function setGalaxy($galaxy)
    {
        $this->setData('galaxy', $galaxy);

        return $this;
    }

    public function getGalaxy()
    {
        return (int) $this->getData('galaxy');
    }

    public function setSystem($system)
    {
        $this->setData('system', $system);

        return $this;
    }

    public function getSystem()
    {
        return (int) $this->getData('system');
    }

    public function setPosition($position)
    {
        $this->setData('planet', $position);

        return $this;
    }

    public function getPosition()
    {
        return (int) $this->getData('planet');
    }

    public function setType($type)
    {
        $this->setData('planet_type', $type);

        return $this;
    }

    public function getType()
    {
        return (int) $this->getData('planet_type');
    }

    public function getElement($elementId)
    {
        $fields = Legacies_Empire_Model_Game_FieldsAlias::getSingleton();

        return $this->getData($fields[$elementId]);
    }

    public function setElement($elementId, $level)
    {
        $fields = Legacies_Empire_Model_Game_FieldsAlias::getSingleton();

        return $this->setData($fields[$elementId], $level);
    }

    public function hasElement($elementId, $levelRequired = 0)
    {
        $fields = Legacies_Empire_Model_Game_FieldsAlias::getSingleton();

        return $this->hasData($fields[$elementId]) && Math::comp($this->getElement($elementId), $levelRequired) > 0;
    }

    public function appendQueue($buildingId)
    {
        $types = Legacies_Empire_Model_Game_Types::getSingleton();

        if (!$types->is($buildingId, Legacies_Empire::TYPE_BUILDING)) {
            return $this;
        }

        if (!$this->checkAvailability($buildingId)) {
            return $this;
        }

        // Dispatch event
        Legacies::dispatchEvent('planet.building.append-queue.before', array(
            'ship_id'  => $shipId,
            'qty'      => $qty,
            'shipyard' => $this,
            'planet'   => $this->_currentPlanet,
            'user'     => $this->_currentUser
            ));

        foreach ($this->_resourcesTypes as $resourceType) {
            $this->setData($resourceType, Math::sub($this->getData($resourceType), $resourcesNeeded[$resourceType]));
        }

        // Dispatch event
        Legacies::dispatchEvent('planet.shipyard.append-queue.after', array(
            'ship_id'  => $shipId,
            'qty'      => $qty,
            'shipyard' => $this,
            'planet'   => $this->_currentPlanet,
            'user'     => $this->_currentUser
            ));

        return $this;
    }

    /**
     * Check if a building is actually buildable on the current planet,
     * depending on the technology and buildings requirements.
     *
     * @param int $buildingId
     * @return bool
     */
    public function checkAvailability($buildingId)
    {
        $types = Legacies_Empire_Model_Game_Types::getSingleton();
        $requirements = Legacies_Empire_Model_Game_Requirements::getSingleton();

        if (!isset($requirements[$buildingId]) || empty($requirements[$buildingId])) {
            return true;
        }

        foreach ($requirements[$buildingId] as $requirement => $level) {
            if ($types->is($requirement, Legacies_Empire::TYPE_BUILDING) && $this->_currentPlanet->hasElement($requirement, $level)) {
                continue;
            } else if ($types->is($requirement, Legacies_Empire::TYPE_RESEARCH) && $this->_currentUser->hasElement($requirement, $level)) {
                continue;
            } else if ($types->is($requirement, Legacies_Empire::TYPE_DEFENSE) && $this->_currentPlanet->hasElement($requirement, $level)) {
                continue;
            } else if ($types->is($requirement, Legacies_Empire::TYPE_SHIP) && $this->_currentPlanet->hasElement($requirement, $level)) {
                continue;
            }
            return false;
        }

        try {
            // Dispatch event. Throw an exception to break the avaliability.
            Legacies::dispatchEvent('planet.shipyard.check-availability', array(
                'ship_id'  => $buildingId,
                'shipyard' => $this,
                'planet'   => $this->_currentPlanet,
                'user'     => $this->_currentUser
                ));
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    public static function registrationListener($eventData)
    {
        if (isset($eventData['user'])) {
            $user = $eventData['user'];
            $request = $eventData['request'];

            if ($user === null || !$user instanceof Legacies_Empire_Model_User || $user->getId()) {
                return;
            }
            if ($request === null || !$request instanceof Legacies_Core_Controller_Request) {
                return;
            }

            $collection = new Legacies_Core_Collection('planets');
            $collection
                ->column(array(
                    'galaxy' => 'planet.galaxy',
                    'system' => 'planet.system',
                    'count'  => 'COUNT(planet.id)'
                    ))
                ->group('planet.galaxy')
                ->group('planet.system')
                ->where('planet.planet_type=1')
                ->order('COUNT(planet.id)', 'ASC')
                ->order('RAND()', 'ASC')
                ->limit(1)
            ;

            $params = array();
            $galaxy = $request->getParam('system');
            if ($galaxy !== null) {
                $collection->where('planet.galaxy=:galaxy');
                $params['galaxy'] = $galaxy;

                $systems = explode(',', $request->getParam('system'));
                if (is_array($systems) && count($systems) == 2 && is_int($systems[0]) && is_int($systems[1])) {
                    $collection->where('planet.system IN(' . implode(', ', range($systems[0], $systems[1])) . ')');
                }
            }
            $collection->load($params);

            if ($collection->count() == 0) {
                throw new Exception('No planet to colonize there!'); // FIXME
            }

            $systemInfo = $collection->current();
            if ($systemInfo->getData('count') >= MAX_PLANET_IN_SYSTEM) {
                throw new Exception('No planet to colonize there!'); // FIXME
            }
            $system = $systemInfo->getData('system');
            $galaxy = $systemInfo->getData('galaxy');

            $collection = new Legacies_Core_Collection('planets');
            $collection
                ->column(array('position' => 'planet.position'))
                ->where('planet.planet_type=1')
                ->where('planet.planet_type=:system')
                ->load()
            ;
            $positions = range(1, MAX_PLANET_IN_SYSTEM);
            foreach ($collection as $planet) {
                $key = array_search($planet->getData('position'), $positions);
                if ($key !== false) {
                    unset($positions[$key]);
                }
            }
            $key = array_rand($positions, 1);
            $finalPosition = $positions[$key];

            $planet = new self();
            $planet
                ->setData('id_owner', $user->getId())
                ->setData('name', $request->getParam('planet'))
                ->setData('galaxy', $galaxy)
                ->setData('system', $system)
                ->setData('position', $finalPosition)
                ->setData('planet_type', 1)
            ;

            Legacies::dispatchEvent('planet.init', array(
                'planet' => $planet,
                'user'   => $user
                ));

            $planet
                ->setData('field_max', 163)
                ->setData('field_current', 0)
                ->save()
            ;

            $user
                ->setData('id_planet', $planet->getId())
                ->setData('current_planet', $planet->getId())
            ;
        }
    }

    public static function planetUpdateListener($eventData)
    {
        if (isset($eventData['planet'])) {
            $planet = $eventData['planet'];

            if (isset($eventData['time'])) {
                $time = $eventData['time'];
            } else {
                $time = time();
            }

            if ($planet === null || !$planet instanceof Legacies_Empire_Model_Planet) {
                return;
            }

            $user = $planet->getUser();
            if (($queue = $planet->getData('b_building_id')) != '0') { //FIXME: refactor buildings construciton list
                $explodedQueue = explode(';', $queue);
                foreach ($explodedQueue as $item) {
                    $partialTime = $planet->getData('b_building');

                    if ($partialTime < $time) {
                        $planet->updateResources($partialTime);
                        /*if (CheckPlanetBuildingQueue($planet, $user)) {
                            SetNextQueueElementOnTop($planet, $user);
                        }*/
                    } else {
                        $planet->updateResources($time);
                        break;
                    }
                }
            } else {
                $planet->updateResources($time);
            }
            $planet->setData('last_update', $time);
        }
    }
}