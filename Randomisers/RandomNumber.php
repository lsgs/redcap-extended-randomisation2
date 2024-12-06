<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;

/**
 * RandomNumber
 * - When randomising to a text field, generate a random number between 0 and 1
 * @author luke.stevens
 */
class RandomNumber extends AbstractRandomiser {
    public const USE_WITH_OPEN = true;
    public const USE_WITH_CONCEALED = true;
    protected const LABEL = 'Random Number (0-1)';
    protected const DESC = 'Generate a random number floating point number between 0 and 1.';

    public function randomise() {
        $r = $this->getRandomNumber();
        $tableCol = ($this->isBlinded) ? 'target_field' : 'target_field_alt';
        \REDCap::updateRandomizationTableEntry($this->project_id, $this->rid, $this->next_aid, $tableCol, $r, $this->module->getModuleName());
        $this->moduleLogEvent("Allocation id {$this->next_aid} allocated random number $r");
        return null;
    }

    public function getConfigOptionDescription(): string { 
        $desc = static::DESC.'<ul>';
        $desc .= '<li><i class="fas fa-envelope-open text-success fs14 mr-1""></i>'.\RCView::tt('random_161').\RCView::tt('colon').' Regular group allocation, random number recorded as randomization number (<code>[rand-number]</code>)</li>';
        $desc .= '<li><i class="fas fa-envelope text-danger fs14 mr-1""></i>'.\RCView::tt('random_162').\RCView::tt('colon').' Random number overwrites uploaded target randomization number value for allocation.</li>';
        return $desc.'</ul>'; 
    }

    protected function getConfigOptionMarkupFields(): string { return ''; }
    
    protected function updateRandomisationState(array $stratification, int $allocation) { }
}