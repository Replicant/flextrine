<?php
/**
 * Copyright (C) 2012 Dave Keen http://www.actionscriptdeveloper.co.uk
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Flextrine\Tools;

use Doctrine\ORM\EntityManager,
	Doctrine\ORM\Mapping\ClassMetadata,
	Doctrine\ORM\Mapping\AssociationMapping,
    Doctrine\Common\Util\Inflector;

require_once('vlib/vlibTemplate.php'); 

class AS3EntityGenerator {

	private $em;

	/** Whether or not to generation annotations */
    private $_generateAnnotations = false;

    /** Whether or not to generate association helper methods */
    private $_generateAssociationHelperMethods = false;

    /** Whether or not to update the entity class if it exists already */
    private $_updateEntityIfExists = false;

    /** Whether or not to re-generate entity class if it exists already */
    private $_regenerateEntityIfExists = false;

	public function __construct(EntityManager $em) {
		$this->em = $em;
	}

	public function generate(array $metadatas, $outputDirectory = null) {
		$generatedClasses = array();
		foreach ($metadatas as $metadata) {
			if ($metadata->isInheritanceTypeJoined() && $metadata->rootEntityName == $metadata->name) {
				$generatedClasses[str_replace("\\", DIRECTORY_SEPARATOR, $metadata->name."InterfaceBase.as")] = array("code" => $this->_generateInheritanceInterfaceBase($metadata), "overwrite" => true);		
				$generatedClasses[str_replace("\\", DIRECTORY_SEPARATOR, $metadata->name.".as")] = array("code" => $this->_generateInheritanceInterfaceChild($metadata), "overwrite" => false);		
			} else {
				$generatedClasses[str_replace("\\", DIRECTORY_SEPARATOR, $metadata->name."EntityBase.as")] = array("code" => $this->_generateBase($metadata), "overwrite" => true);
				$generatedClasses[str_replace("\\", DIRECTORY_SEPARATOR, $metadata->name.".as")] = array("code" => $this->_generateChild($metadata), "overwrite" => false);
			}
		}

		if ($outputDirectory) {
			$this->writeGeneratedClasses($generatedClasses, $outputDirectory);
		} else {
			return $generatedClasses;
		}
	}

	private function writeGeneratedClasses($generatedClasses, $outputDirectory) {
		foreach ($generatedClasses as $path => $contents) {
			$fullPath = $outputDirectory.DIRECTORY_SEPARATOR.$path;
			$dir = dirname($fullPath);

			if (!is_dir($dir))
				mkdir($dir, 0777, true);
			
			$isNew = !file_exists($fullPath);
			
			// Write if the file is new, the user has explicit said to overwrite entities or if overwrite is set
			if ($isNew || $this->_regenerateEntityIfExists || $contents["overwrite"]) {
				file_put_contents($fullPath, $contents["code"]);
				echo "Wrote entity to $path.\n";
			} else {
				echo "Entity ($path) already exists.\n";
			}
		}
	}
	
	private function _generateBase($metadata) {
		$template = new \vlibTemplate(dirname(__FILE__)."/templates/as3/entitybase.as.tpl");
		
		// Set the package, remote class and local class name
		$template->setVar("package", str_replace("\\", ".", $metadata->namespace));
		$template->setVar("remoteclass", str_replace("\\", "/", $metadata->name));
		$template->setVar("classname", $this->stripPackageFromClassName($metadata->name));
		
		// Get the identifiers
		$identifiers = $metadata->identifier;
		
		$identifiersLoop = array();
		foreach ($identifiers as $identifier)
			$identifiersLoop[] = array("identifier" => $identifier);
		
		if (sizeof($identifiersLoop) > 0) $template->setLoop("identifiersloop", $identifiersLoop);
		
		// Get the fields.  Note that id fields are always of type String.
		$fieldMappings = $metadata->fieldMappings;
		
		$fieldsLoop = array();
		foreach ($fieldMappings as $name => $mapping) {
			$fieldsLoop[] = array(
				"name" => $name,
				"type" => (isset($mapping['id'])) ? "String" : $this->columnTypeToAS3Type($mapping['type']),
				"id" => isset($mapping['id'])
			);
		}
		
		if (sizeof($fieldsLoop) > 0) $template->setLoop("fieldsloop", $fieldsLoop);
		
		// Get the associations
		$associationMappings = $metadata->associationMappings;
		
		$associationsLoop = array();
		
		foreach ($associationMappings as $name => $mapping) {
			if ($mapping["type"] & ClassMetadata::TO_ONE) {
				$type = $this->stripPackageFromClassName($mapping["targetEntity"]);
			} else if ($mapping["type"] & ClassMetadata::TO_MANY) {
				$type = "PersistentCollection";
			}
			
			// Work out if the association is uni or bi-directional and the opposite attribute name (this will be null if unidirectional)
			$bidirectional = !(is_null($mapping["mappedBy"]) && is_null($mapping["inversedBy"]));
			$oppositeAssociationName = $mapping["isOwningSide"] ? $mapping["inversedBy"] : $mapping["mappedBy"];
			
			$associationsLoop[] = array(
				"name" => $name,
				"type" => $type,
				"side" => ($mapping["isOwningSide"]) ? "owning" : "inverse",
				"mappedBy" => $mapping["mappedBy"],
				"bidirectional" => $bidirectional,
				"oppositeAssociationName" => $oppositeAssociationName,
				"oppositeCardinality" => ($mapping["type"] & ClassMetadata::MANY_TO_ONE || $mapping["type"] & ClassMetadata::MANY_TO_MANY) ? "*" : "1",
				"package" => str_replace("\\", ".", $mapping["targetEntity"])
			);
		}
		
		if (sizeof($associationsLoop) > 0) $template->setLoop("associationsloop", $associationsLoop);
		
		return $template->grab();
	}
	
	private function _generateChild($metadata) {
		$template = new \vlibTemplate(dirname(__FILE__)."/templates/as3/entity.as.tpl");
		
		// Set the package, remote class and local class name
		$template->setVar("package", str_replace("\\", ".", $metadata->namespace));
		$template->setVar("remoteclass", str_replace("\\", ".", $metadata->name));
		$template->setVar("classname", $this->stripPackageFromClassName($metadata->name));
		
		// If the rootEntityName isn't us then we want to implement that interface (this is how Flextrine deals with inheritance)
		if (!($metadata->rootEntityName == $metadata->name)) {
			$template->setVar("implementspackage", str_replace("\\", ".", $metadata->rootEntityName));
			$template->setVar("implements", $this->stripPackageFromClassName($metadata->rootEntityName));
		}
		
		return $template->grab();
	}
	
	private function _generateInheritanceInterfaceBase($metadata) {
		$template = new \vlibTemplate(dirname(__FILE__)."/templates/as3/inheritanceinterfacebase.as.tpl");
		
		// Set the package and local class name
		$template->setVar("package", str_replace("\\", ".", $metadata->namespace));
		$template->setVar("classname", $this->stripPackageFromClassName($metadata->name));
		
		$fieldMappings = $metadata->fieldMappings;
		
		$fieldsLoop = array();
		foreach ($fieldMappings as $name => $mapping) {
			$fieldsLoop[] = array(
				"name" => $name,
				"type" => (isset($mapping['id'])) ? "String" : $this->columnTypeToAS3Type($mapping['type']),
			);
		}
		
		if (sizeof($fieldsLoop) > 0) $template->setLoop("fieldsloop", $fieldsLoop);
		
		// Get the associations
		$associationMappings = $metadata->associationMappings;
		
		$associationsLoop = array();
		
		foreach ($associationMappings as $name => $mapping) {
			if ($mapping["type"] & ClassMetadata::TO_ONE) {
				$type = $this->stripPackageFromClassName($mapping["targetEntity"]);
			} else if ($mapping["type"] & ClassMetadata::TO_MANY) {
				$type = "PersistentCollection";
			}
			
			$associationsLoop[] = array(
				"name" => $name,
				"type" => $type,
				"package" => str_replace("\\", ".", $mapping["targetEntity"])
			);
		}
		
		if (sizeof($associationsLoop) > 0) $template->setLoop("associationsloop", $associationsLoop);
		
		return $template->grab();
	}
	
	private function _generateInheritanceInterfaceChild($metadata) {
		$template = new \vlibTemplate(dirname(__FILE__)."/templates/as3/inheritanceinterface.as.tpl");
		
		// Set the package and local class name
		$template->setVar("package", str_replace("\\", ".", $metadata->namespace));
		$template->setVar("classname", $this->stripPackageFromClassName($metadata->name));
		
		return $template->grab();
	}
		
	private function columnTypeToAS3Type($columnType) {
		switch ($columnType) {
			case "integer": // Since we can't set AS3 ints to null (they can only be 0) use a Number
			case "smallint":
				return "int";
			case "bigint":
			case "decimal":
			case "time": // There is no equivalent in AS3 so map time to a number (ms)
				return "Number";
			case "boolean":
				return "Boolean";
			case "text":
			case "string":
				return "String";
			case "date": // Date should be a normal AS3 date with HH:MM:SS == 00:00:00
			case "datetime":
				return "Date";
			default:
				return "String";
		}
	}
	
	private function stripPackageFromClassName($qualifiedClassName) {
		return preg_replace("(.*\\\\)", "", $qualifiedClassName);
	}

    /**
     * Set whether or not to try and update the entity if it already exists
     *
     * @param bool $bool
     * @return void
     */
    public function setUpdateEntityIfExists($bool) {
        $this->_updateEntityIfExists = $bool;
    }

    /**
     * Set whether or not to regenerate the entity if it exists
     *
     * @param bool $bool
     * @return void
     */
    public function setRegenerateEntityIfExists($bool) {
        $this->_regenerateEntityIfExists = $bool;
    }

    /**
     * Set whether or not to generate association helper methods for the entity
     *
     * @param bool $bool
     * @return void
     */
    public function setGenerateAssociationHelperMethods($bool) {
        $this->_generateAssociationHelperMethods = $bool;
    }
	
}