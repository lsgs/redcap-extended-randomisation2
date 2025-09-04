<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;

/**
 * RandomGroup
 * - Allocate a schedule entry from the appropriate stratum at random rather than sequentially
 * @author luke.stevens
 */
class RandomGroup extends AbstractRandomiser {
    public const USE_WITH_OPEN = true;
    public const USE_WITH_CONCEALED = false;
    public const EXTEND_ALLOC_TABLE_ENTRY_OPEN = false;
    public const EXTEND_ALLOC_TABLE_ENTRY_CONCEALED = false;
    protected const LABEL = 'Random Group';
    protected const DESC = 'Allocates an available schedule entry from the appropriate stratum at random rather than sequentially.';
    
    public function randomise() {

        // n.b. current project status dev/prod is determined via "next available"
        $sql = "select free.aid
                from redcap_randomization_allocation free
                inner join redcap_randomization_allocation nextavail
                  on free.rid=nextavail.rid and free.project_status=nextavail.project_status 
                     and coalesce(free.group_id,'')=coalesce(nextavail.group_id,'') ";
        for ($i=1; $i <= 15; $i++) { 
            $sql .= "and coalesce(free.source_field$i,'')=coalesce(nextavail.source_field$i,'') ";
        }
        $sql .= "where nextavail.rid=? and nextavail.aid=? and free.is_used_by is null";
        
        $q = $this->module->query($sql, array($this->rid, $this->next_aid));
        $remainingAid = \db_fetch_assoc_all($q);

        if (sizeof($remainingAid)===0) return '0';

        $randomAidIdx = $this->getRandomNumber(0, sizeof($remainingAid), true);
        $randomAid = $remainingAid[$randomAidIdx]['aid'];

        $this->moduleLogEvent("Randomly selected available allocation id is $randomAid");

        return $this->allocateAid($randomAid);
    }

    protected function getConfigOptionMarkupFields(): string { return ''; }

    protected function updateRandomisationState(array $stratification, int $allocation) { }
}