<?php
namespace Oro\Bundle\FlexibleEntityBundle\Entity;

use Oro\Bundle\FlexibleEntityBundle\Entity\Mapping\AbstractEntityAttribute;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Base entity attribute
 *
 * @author    Nicolas Dupont <nicolas@akeneo.com>
 * @copyright 2012 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 *
 * @ORM\Table(
 *     name="oro_flexibleentity_attribute", indexes={@ORM\Index(name="searchcode_idx", columns={"code"})},
 *     uniqueConstraints={@ORM\UniqueConstraint(name="searchunique_idx", columns={"code", "entity_type"})}
 * )
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @UniqueEntity("code")
 */
class Attribute extends AbstractEntityAttribute
{

    /**
     * Overrided to change target entity name
     *
     * @var ArrayCollection $options
     *
     * @ORM\OneToMany(
     *     targetEntity="AttributeOption", mappedBy="attribute", cascade={"persist", "remove"}, orphanRemoval=true
     * )
     * @ORM\OrderBy({"sortOrder" = "ASC"})
     */
    protected $options;

    /**
     * @ORM\Column(name="sort_order", type="integer")
     */
    protected $sortOrder = 0;

    /**
     * Convert defaultValue to UNIX timestamp if it is a DateTime object
     *
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function convertDefaultValueToTimestamp()
    {
        if ($this->getDefaultValue() instanceof \DateTime) {
            $this->setDefaultValue($this->getDefaultValue()->format('U'));
        }
    }

    /**
     * Convert defaultValue to DateTime if attribute type is date
     *
     * @ORM\PostLoad
     */
    public function convertDefaultValueToDatetime()
    {
        if ($this->getDefaultValue()) {
            if (strpos($this->getAttributeType(), 'DateType') !== false) {
                $date = new \DateTime();
                $date->setTimestamp(intval($this->getDefaultValue()));

                $this->setDefaultValue($date);
            }
        }
    }

    /**
     * Convert defaultValue to integer if attribute type is boolean
     *
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function convertDefaultValueToInteger()
    {
        if ($this->getDefaultValue() !== null) {
            if (strpos($this->getAttributeType(), 'BooleanType') !== false) {
                $this->setDefaultValue((int) $this->getDefaultValue());
            }
        }
    }

    /**
     * Convert defaultValue to boolean if attribute type is boolean
     *
     * @ORM\PostLoad
     */
    public function convertDefaultValueToBoolean()
    {
        if ($this->getDefaultValue() !== null) {
            if (strpos($this->getAttributeType(), 'BooleanType') !== false) {
                $this->setDefaultValue((bool) $this->getDefaultValue());
            }
        }
    }

    public function getSortOrder()
    {
        return $this->sortOrder;
    }

    public function setSortOrder($sortOrder)
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
