<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;

/**
 * Default
 * - Facilitate access to certain randomiser properties and methods for randomisations using the REDCap default allocation
 * @author luke.stevens
 */
class REDCapDefault extends AbstractRandomiser {
    public const USE_WITH_OPEN = true;
    public const USE_WITH_CONCEALED = true;
    protected const LABEL = 'REDCap Default';
    protected const DESC = 'The <em>Extended Randomization</em> external module will not be active for this randomization. Randomization will be performed using REDCap\'s standard randomization mechanism.';
    
    public function randomise() { }
    protected function getConfigOptionMarkupFields(): string { return ''; }
    protected function updateRandomisationState(array $stratification, int $allocation) { }
}