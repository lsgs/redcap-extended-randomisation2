<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;

interface IRandomiserConfiguration {
    public function getName(): string;
    public function getLabel(): string;
    public function getDescription(): string;
    public function getOptionArray(): array;
    public function setName(string $s): void;
    public function setLabel(string $s): void;
    public function setDescription(string $s): void;
    public function setOptionArray(array $a): void;
}

/**
 * RandomiserConfig
 *
 * @author luke.stevens
 */
class RandomiserConfig implements IRandomiserConfiguration {
    protected string $randomiserName;
    protected string $randomiserLabel;
    protected string $randomiserDescription;
    protected array $randomiserOptionArray;
    public function getName(): string { return $this->randomiserName; }
    public function getLabel(): string { return $this->randomiserLabel; }
    public function getDescription(): string { return $this->randomiserDescription; }
    public function getOptionArray(): array { return $this->randomiserOptionArray; }
    public function setName(string $s): void { $this->randomiserName = $s; }
    public function setLabel(string $s): void { $this->randomiserLabel = $s; }
    public function setDescription(string $s): void { $this->randomiserDescription = $s; }
    public function setOptionArray(array $a): void { $this->randomiserOptionArray = $a; }
    public function __construct(string $name, string $label, string $description, array $optionArray) {
        $this->setName($name);
        $this->setLabel($label);
        $this->setDescription($description);
        $this->setOptionArray($optionArray);
    }
}