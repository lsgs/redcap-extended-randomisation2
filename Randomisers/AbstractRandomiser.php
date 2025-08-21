<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;

/**
 * AbstractRandomiser
 *
 * @author luke.stevens
 */
abstract class AbstractRandomiser {
    public const REDCAP_DEFAULT = 'REDCapDefault';
    public const USE_WITH_OPEN = true;
    public const USE_WITH_CONCEALED = true;
    protected const LABEL = '-';
    protected const DESC = '-';
    protected ExtendedRandomisation2 $module;
    protected $project_id;
    protected $project_status;
    protected $rid;
    protected array $attrs;
    protected $isBlinded;
    protected $config_current_index;
    protected $config_current_randomiser_type;
    protected $config_current_settings_array;
    protected $randomisation_state = null;
    protected $record;
    protected $strata_field_values;
    protected $group_id;
    protected $next_aid;
    protected $seed = null;
    protected $seedSequence = null;
    protected $ridTotal = 0;
    public static $ProdEditableSettings = array(); // any settings that are editable in prod

    public static function getClassNameWithoutNamespace() { return str_replace(__NAMESPACE__ . '\\', '', get_called_class()); }
    public function getConfigOptionName(): string { return static::getClassNameWithoutNamespace(); }
    public function getConfigOptionLabel(): string { return static::LABEL; }
    public function getConfigOptionDescription(): string { return static::DESC; }
    public function getNextAid() { return $this->next_aid; }
    abstract protected function getConfigOptionMarkupFields(): string;

    /**
     * Randomise the current record, including any update to the allocation table
     * @param string $record
     * @param array $strata_field_values
     * @param ?int $group_id
     * @return int Return the id of the allocated table entry
     */
    abstract public function randomise();

    /**
     * Update the randomisation state i.e. history of previous randomisations
     * @param array $stratification
     * @param int $allocation
     */
    abstract protected function updateRandomisationState(array $stratification, int $allocation);
    
    public static function getRandomiserNames() {
        $names = array(self::REDCAP_DEFAULT);
        $dir = dirname(__FILE__);
        foreach (glob("$dir/*.php") as $path) {
            $filename = array_pop(explode('/',$path));
            $classname = str_replace('.php','',$filename);
            if ($classname!='AbstractRandomiser' && $classname!=self::REDCAP_DEFAULT) {
                require_once $path;
                //if (is_subclass_of($classname, self::getClassNameWithoutNamespace())) {
                $names[] = $classname;
            }
        }
        return $names;
    }

    /**
     * __construct
     * Create a new randomiser intitialised with project/randomisation/config information (but not record context)
     */
    public function __construct(int $randomization_id, ExtendedRandomisation2 $module, bool $applyConfig=true) {
        global $Proj;
        $this->project_id = PROJECT_ID;
        $this->project_status = $Proj->project['status'];
        $this->module = $module;
        $this->rid = $randomization_id;
        $this->attrs = $this->module->getRandAttrs($this->rid);
        $this->isBlinded = $this->attrs['isBlinded'];
        $this->seed = intval($module->getProjectSetting('seed'));
        $this->seedSequence = intval($module->getProjectSetting('seed-sequence'));

        list($thisRidKey, $randomiserType, $randConfigText) = $module->getRandConfigSettings($randomization_id);
        $this->config_current_index = $thisRidKey;
        $this->config_current_randomiser_type = $randomiserType;
        $this->config_current_settings_array = \json_decode($randConfigText, true) ?? array();
        if ($applyConfig && !is_array($this->config_current_settings_array)) throw new \Exception("Could not read module config for randomization id $randomization_id, '$randomiserType'");
    }

    public function applySavedConfig(): void {
        if (is_array($this->config_current_settings_array) && !empty($this->config_current_settings_array)) {
            $configure = $this->validateConfigSettings($this->config_current_settings_array);
            if ($configure!==true) {
                throw new \Exception("Error in randomiser config: $configure");
            }
        }
    }

    /**
     * initialiseRecordRandomiser()
     * Initialise the randomiser with record data ready to perform randomisation
     * @param string $record
     * @param array $strata_field_values
     * @param ?int $group_id
     * @return void
     */
    public function initialiseRecordRandomiser(string $record, array $strata_field_values, ?int $group_id): void {
        $this->record = $record;
        $this->strata_field_values = $strata_field_values;
        $this->group_id = $group_id;
        $this->next_aid = $this->readNextAllocationId();
    }

    protected function readNextAllocationId() {
        if (!isset($this->record)) throw new \Exception("An error occurred in reading the randomization allocation table: no record specified.");
        $groupName = (is_null($this->group_id)) ? null : \REDCap::getGroupNames(true, $this->group_id);
        $nextAllocId = \REDCap::getNextRandomizationAllocation($this->project_id, $this->rid, $this->strata_field_values, $groupName);
        if ($nextAllocId===false) {
            throw new \Exception("An error occurred in reading the randomization allocation table: rid=$this->rid; record=$this->record; group_id=$this->group_id; fields=".implode(';',array_keys($this->strata_field_values))."; values=".implode(';',array_values($this->strata_field_values)));
        } else if ($nextAllocId==='0') {
            // Randomization allocation table exhausted
        }
        return $nextAllocId;
    }

    protected function allocateAid($aid) {
        try {
            if (!isset($this->record)) throw new \Exception("An error occurred updating the randomization allocation table: no record specified.");
            $result = \REDCap::updateRandomizationTableEntry($this->project_id, $this->rid, $aid, 'is_used_by', $this->record, $this->module->getModuleName());
            $message = '';
        } catch (\Throwable $th) {
            $result = false;
            $message = $th->getMessage();
        }
        return ($result) ? $aid : $message;
    }

    /**
     * getRandomNumber()
     * Get a random number in the range specified, optionally rounded down to whole number
     * @param int $min
     * @param int $max
     * @param bool $returnFloorInt
     * @return float
     */
    public function getRandomNumber(int $min=0, int $max=1, bool $returnFloorInt=false): float {
        $min = ($min>$max) ? $max : $min;
        $max = ($min>$max) ? $min : $max;

        if (empty($this->seed)) {
            $rn = (float)mt_rand();
        } else {
            // if seed specified then use the nth in sequence corresponding to seed, then increment stored counter
            mt_srand($this->seed);
            $this->seedSequence++;
            for ($i=0; $i < $this->seedSequence; $i++) { 
                $rn = (float)mt_rand();
            }
            $this->module->setProjectSetting('seed-sequence', "{$this->seedSequence}");
        }

        $out = $min + (($max-$min) * $rn/(float)mt_getrandmax());
        return ($returnFloorInt) ? floor($out) : $out;
    }

    protected function moduleLogEvent($message) {
        $randomiser = static::getClassNameWithoutNamespace();
        $description = "$randomiser: $message";
        \REDCap::logEvent($this->module->getModuleName(), $description, '', $this->record, $this->attrs['targetEvent']);
    }

    public function getConfigOptionMarkup(): string {
        $randomiserName = $this->getConfigOptionName();
        $form = '';
        try {
            $description = $this->getConfigOptionDescription();
            $form = $this->getConfigOptionMarkupFields();
        } catch (\Throwable $th) {
            $description = '';
        }
        $html = static::makeConfigOptionMarkup($randomiserName, $description, $form);
        return $html;
    }

    protected static function makeConfigOptionMarkup($randomiserName, $description, $settingsForm): string {
        return \RCView::div(array('id'=>'extrnd-opt-config-'.$randomiserName, 'class'=>'extrnd-opt-config extrnd-init-hidden'),
            \RCView::p(array(), $description).
            \RCView::div(array(), $settingsForm)
        );
    }

    public function validateConfigSettings(array &$settings) {
        return true;
    }

    /**
     * isConfigEditable
     * Configuration will not be editable once a record is randomised in Production
     * - static::$ProdEditableSettings is array of settings that are allowed to be edited in prod, but this method will still return false
     * @return bool
     */
    public function isConfigEditable() : bool {
        $sql = "select count(*) as n_prod_rand from redcap_randomization_allocation where rid = ? and is_used_by is not null and project_status = 1 group by rid";
        $result = $this->module->query($sql, [$this->rid]);
        if ($result->num_rows === 0) return true;
        $n_prod_rand = $result->fetch_assoc()['n_prod_rand'];
        return $n_prod_rand === 0; // editable when 0 prod rand
    }
}
