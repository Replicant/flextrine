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

namespace Flextrine\Internal;

use Flextrine\Operations\CollectionChangeOperation;

use Doctrine\ORM\Events,
	Doctrine\ORM\Event\OnFlushEventArgs,
	Doctrine\ORM\Mapping\ClassMetadata,
	Doctrine\ORM\Proxy\Proxy;

class FlushExecutor {
	
	private $em;

	private $deserializationWalker;
	
	private $changeSets;
	
	private $temporaryUidMap;
	
	private $persistRemoteOperations = array();
	
	private $propertyChangeRemoteOperations = array();
	
	private $collectionChangeRemoteOperations = array();
	
	private $removeRemoteOperations = array();
	
	private $originalCascades = array();
	
	function __construct($em, $flushSet, $deserializationWalker) {
		$this->em = $em;

		$this->deserializationWalker = $deserializationWalker;

		if ($flushSet) {
			// Get the remote operations out of the flushset for each operation type
			foreach ($flushSet->persists as $persistRemoteOperation)
				$this->persistRemoteOperations[] = (object)$persistRemoteOperation;
			
			foreach ($flushSet->propertyChanges as $propertyChangeRemoteOperation)
				$this->propertyChangeRemoteOperations[] = (object)$propertyChangeRemoteOperation;
				
			foreach ($flushSet->collectionChanges as $collectionChangeRemoteOperation)
				$this->collectionChangeRemoteOperations[] = (object)$collectionChangeRemoteOperation;
				
			foreach ($flushSet->removes as $removeRemoteOperation)
				$this->removeRemoteOperations[] = (object)$removeRemoteOperation;
		}
		
	}
	
	function __destruct() {
		
	}
	
	public function flush() {
		$this->temporaryUidMap = array();
		
		$this->changeSets = array("entityInsertions" => array(),
								  "entityUpdates" => array(),
								  "entityDeletions" => array()/*,
								  "collectionUpdates" => array(),
								  "collectionDeletions" => array()*/);
		
		// Add an event listener to hook into the flush and retrieve the changesets
		$this->em->getEventManager()->addEventListener(array(Events::onFlush), $this);
		
		// Persist, update and remove as required
		$this->doPersists();
		$this->doPropertyChanges();
		$this->doCollectionChanges();
		$this->doRemoves();
		
		try {
			// Perform the flush
			$this->em->flush();
		} catch (\Exception $e) {
			throw $e;
		}
		
		// Add any auto-generated ids into the changeset
		$this->doAddPersistedIds();
		
		// Add any deleted ids back into the changeset
		$this->doAddRemovedIds();
		
		// Add the temporary uid map to the changeset so that Flextrine can match up the persisted object with the id-less object in the repository
		$this->changeSets["temporaryUidMap"] = $this->temporaryUidMap;
		
		return $this->changeSets;
	}
	
	private function doPersists() {
		foreach ($this->persistRemoteOperations as $persistOperation) {
			$entity = $this->deserializationWalker->walk($persistOperation->entity);
			
			// Persist the entity
			$this->em->persist($entity);
			
			// Add a map from the object hash to the temporary uid of the object
			$this->temporaryUidMap[spl_object_hash($entity)] = $persistOperation->temporaryUid;
		}
	}
	
	private function doPropertyChanges() {
		foreach ($this->propertyChangeRemoteOperations as $propertyChangeOperation) {
			$class = $this->em->getClassMetadata(get_class($propertyChangeOperation->entity));
			
			$entity = $this->getManagedEntity($propertyChangeOperation->entity);
			
			// Determine whether we are updating a field or a (single valued) association.
			if (array_key_exists($propertyChangeOperation->property, $class->fieldMappings)) {
				// Set the new value of the property (this should use reflection, but for now do it dynamically)
				$entity->{$propertyChangeOperation->property} = $propertyChangeOperation->value;
			} else if (array_key_exists($propertyChangeOperation->property, $class->associationMappings)) {
				if ($class->associationMappings[$propertyChangeOperation->property]['type'] & ClassMetadata::TO_MANY)
					throw new \Exception("Flextrine attempted to execute a propertyChange event against a many valued collection.  This should not happen!");
				
				// Set the new value of the property (this should use reflection, but for now do it dynamically).  This doesn't need to be initialized.
				$newAssoc = $this->getManagedEntity($propertyChangeOperation->value, false);
				$entity->{$propertyChangeOperation->property} = $newAssoc;
			}
			
			// TODO: Unless there is a flush here, weird stuff happens - I'm not sure why.  At some point work this out and remove it.
			//$this->em->flush();
		}
	}
	
	private function doCollectionChanges() {
		foreach ($this->collectionChangeRemoteOperations as $collectionChangeOperation) {
			$class = $this->em->getClassMetadata(get_class($collectionChangeOperation->entity));
			
			if (!(array_key_exists($collectionChangeOperation->property, $class->associationMappings) && $class->associationMappings[$collectionChangeOperation->property]['type'] & ClassMetadata::TO_MANY))
				throw new \Exception("Flextrine attempted to execute a collectionChange event against a non-existant or single valued association.  This should not happen!");
			
			$entity = $this->getManagedEntity($collectionChangeOperation->entity);
			
			switch ($collectionChangeOperation->type) {
				case CollectionChangeOperation::ADD:
				case CollectionChangeOperation::REMOVE:
					foreach ($collectionChangeOperation->items as $item) {
						// Get the entity to add/remove.  This doesn't need to be initialized.
						$newAssoc = $this->getManagedEntity($item, false);
						
						if ($collectionChangeOperation->type == CollectionChangeOperation::ADD) {
							if (!($entity->{$collectionChangeOperation->property}->contains($newAssoc)))
								$entity->{$collectionChangeOperation->property}->add($newAssoc);
						} else if ($collectionChangeOperation->type == CollectionChangeOperation::REMOVE) {
							if (($entity->{$collectionChangeOperation->property}->contains($newAssoc)))
								$entity->{$collectionChangeOperation->property}->removeElement($newAssoc);
						}
					}
					break;
				case CollectionChangeOperation::RESET:
					$entity->{$collectionChangeOperation->property}->initialize(); // DDC-1180; remove when fixed in Doctrine
					$entity->{$collectionChangeOperation->property}->clear();
					
					foreach ($collectionChangeOperation->items as $item) {
						// Get the entity to add.  This doesn't need to be initialized.
						$newAssoc = $this->getManagedEntity($item, false);
						$entity->{$collectionChangeOperation->property}->add($newAssoc);
					}
					
					break;
			}
		}
	}
	
	private function getManagedEntity($detachedEntity, $forceEntityInitialized = true) {
		if (is_null($detachedEntity))
			return null;
		
		$class = $this->em->getClassMetadata(get_class($detachedEntity));
		
		$idFields = $class->getIdentifier();
		
		$findBy = array();
		foreach ($idFields as $idField)
			$findBy[$idField] = $detachedEntity->$idField; // TODO: This should use reflection
		
		// First try and get the entity from the unit of work, in case we have already loaded (and possibly changed) it.
		$managedEntity = $this->em->getUnitOfWork()->tryGetById($findBy, get_class($detachedEntity));
		
		// If the entity wasn't in the unit of work then get it from the database instead
		if (!$managedEntity) $managedEntity = $this->em->getRepository(get_class($detachedEntity))->findOneBy($findBy);
		
		// If the entity is not initialized then force initialize it (if $initializeEntity is true)
		if ($managedEntity instanceof Proxy && !$managedEntity->__isInitialized__ && $forceEntityInitialized) {
			// Not very pretty, but it currently seems to be the only way to initialize an entity without using a proxied method
			$reflectionClass = new \ReflectionClass($managedEntity);
			$loadMethod = $reflectionClass->getMethod("__load");
			$loadMethod->setAccessible(true);
			$loadMethod->invoke($managedEntity);
		}
		
		return $managedEntity;
	}
	
	private function doRemoves() {
		foreach ($this->removeRemoteOperations as $removeOperation)
			$this->em->remove($this->getManagedEntity($removeOperation->entity));
		
	}
	
	private function doAddPersistedIds() {
		// Update the changeset's entityInsertions objects with the ids (which may have just been created during the flush)
		if (isset($this->changeSets["entityInsertions"])) {
			foreach ($this->changeSets["entityInsertions"] as $oid => $entity) {
				$idObj = $this->em->getUnitOfWork()->getEntityIdentifier($entity);
				
				// TODO: Check that this does in fact work with composite ids
				foreach ($idObj as $id => $idValue)
					$entity->$id = $idValue;
			}
		}
	}
	
	private function doAddRemovedIds() {
		// Update the changeset's entityInsertions objects with the ids (which may have just been created during the flush)
		if (isset($this->changeSets["entityDeletions"])) {
			foreach ($this->changeSets["entityDeletions"] as $oid => $entity) {
				$idObj = $this->entityDeletionIdMap[$oid];
				
				// TODO: Check that this does in fact work with composite ids
				foreach ($idObj as $id => $idValue)
					$entity->$id = $idValue;
			}
		}
	}
	
	private $entityDeletionIdMap;
	
	public function onFlush(OnFlushEventArgs $eventArgs) {
		// Doctrine removes the ids from deleted entities at some point later in the chain, so we need to store them here so that we can inject the values
		// back into the changeset before returning to the client.
		$this->entityDeletionIdMap = array();
		foreach ($this->em->getUnitOfWork()->getScheduledEntityDeletions() as $oid => $entity)
			$this->entityDeletionIdMap[$oid] = $this->em->getUnitOfWork()->getEntityIdentifier($entity);
		
		// We don't use collectionUpdates and colectionDeletions so far, and they seem to make Doctrine do loads of SQL queries so leave them
		// out for the moment.
		// TODO: Now that Flextrine uses change messenging collectionUpdates and collectionDeletions makes more sense again, especially as without then server-side M2n changes won't
		// get picked up by callRemoteFlushMethod.  For now these can stay commented, but this must be addressed at some point.
		$this->changeSets["entityInsertions"] = array_merge($this->em->getUnitOfWork()->getScheduledEntityInsertions(), $this->changeSets["entityInsertions"]);
		$this->changeSets["entityUpdates"] = array_merge($this->em->getUnitOfWork()->getScheduledEntityUpdates(), $this->changeSets["entityUpdates"]);
		$this->changeSets["entityDeletions"] = array_merge($this->em->getUnitOfWork()->getScheduledEntityDeletions(), $this->changeSets["entityDeletions"]);
		/*$this->changeSets["collectionUpdates"] = array_merge($this->em->getUnitOfWork()->getScheduledCollectionUpdates(), $this->changeSets["collectionUpdates"]);
		$this->changeSets["collectionDeletions"] = array_merge($this->em->getUnitOfWork()->getScheduledCollectionDeletions(), $this->changeSets["collectionDeletions"]);*/
		
	}
	
}