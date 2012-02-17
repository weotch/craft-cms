<?php
namespace Blocks;

/**
 *
 */
class ContentService extends BaseService
{
	/* Entries */

	/**
	 * @param $entryId
	 * @return mixed
	 */
	public function getEntryById($entryId)
	{
		$entry = Entry::model()->findByAttributes(array(
			'id' => $entryId,
		));

		return $entry;
	}

	/**
	 * @param $sectionId
	 * @return mixed
	 */
	public function getEntriesBySectionId($sectionId)
	{
		$entries = Entry::model()->findAllByAttributes(array(
			'section_id' => $sectionId,
		));

		return $entries;
	}

	/**
	 * @param $siteId
	 * @return array
	 */
	public function getAllEntriesBySiteId($siteId)
	{
		$entries = Blocks::app()->db->createCommand()
			->select('e.*')
			->from('{{sections}} s')
			->join('{{entries}} e', 's.id = e.section_id')
			->where('s.site_id=:siteId', array(':siteId' => $siteId))
			->queryAll();

		return $entries;
	}

	/**
	 * @param $entryId
	 * @return mixed
	 */
	public function doesEntryHaveSubEntries($entryId)
	{
		$exists = Entry::model()->exists(
			'parent_id=:parentId',
			array(':parentId' => $entryId)
		);

		return $exists;
	}

	/**
	 * @param $entryId
	 * @return mixed
	 */
	public function getEntryVersionsByEntryId($entryId)
	{
		$versions = EntryVersions::model()->findAllByAttributes(array(
			'entry_id' => $entryId,
		));

		return $versions;
	}

	/**
	 * @param $versionId
	 * @return mixed
	 */
	public function getEntryVersionById($versionId)
	{
		$version = EntryVersions::model()->findByAttributes(array(
			'id' => $versionId,
		));

		return $version;
	}

	/* Sections */

	/**
	 * Get all sections for the current site
	 * @return array
	 */
	public function getSections()
	{
		$sections = Section::model()->findAllByAttributes(array(
			'site_id' => Blocks::app()->sites->currentSite->id,
			'parent_id' => null
		));

		return $sections;
	}

	/**
	 * Get the sub sections of another section
	 * @param int $parentId The ID of the parent section
	 * @return array
	 */
	public function getSubSections($parentId)
	{
		return Section::model()->findAllByAttributes(array(
			'parent_id' => $parentId
		));
	}

	/**
	 * Returns a Section instance, whether it already exists based on an ID, or is new
	 * @param int $sectionId The Section ID if it exists
	 * @return Section
	 */
	public function getSection($sectionId = null)
	{
		if ($sectionId)
			$section = $this->getSectionById($sectionId);

		if (empty($section))
			$section = new Section;

		return $section;
	}

	/**
	 * Get a specific section by ID
	 * @param int $sectionId The ID of the section to get
	 * @return Section
	 */
	public function getSectionById($sectionId)
	{
		return Section::model()->findById($sectionId);
	}

	/**
	 * @param $siteId
	 * @param $handle
	 * @return mixed
	 */
	public function getSectionBySiteIdHandle($siteId, $handle)
	{
		$section = Section::model()->findByAttributes(array(
			'handle' => $handle,
			'site_id' => $siteId,
		));

		return $section;
	}

	/**
	 * @param $siteId
	 * @param $handles
	 * @return mixed
	 */
	public function getSectionsBySiteIdHandles($siteId, $handles)
	{
		$sections = Section::model()->findAllByAttributes(array(
			'handle' => $handles,
			'site_id' => $siteId,
		));

		return $sections;
	}

	/**
	 * Saves a section
	 *
	 * @param      $sectionSettings
	 * @param null $sectionBlockIds
	 * @param null $sectionId
	 * @return \Blocks\Section
	 */
	public function saveSection($sectionSettings, $sectionBlockIds = array(), $sectionId = null)
	{
		$section = $this->getSection($sectionId);
		$isNewSection = $section->isNewRecord;

		$section->name = $sectionSettings['name'];
		$section->handle = $sectionSettings['handle'];
		$section->max_entries = $sectionSettings['max_entries'];
		$section->sortable = $sectionSettings['sortable'];
		$section->url_format = $sectionSettings['url_format'];
		$section->template = $sectionSettings['template'];
		$section->site_id = Blocks::app()->sites->currentSite->id;

		if ($section->validate())
		{
			// Start a transaction
			$transaction = Blocks::app()->db->beginTransaction();
			try
			{
				// Save the block
				$section->save();

				// Delete the previous content block selections
				if (!$isNewSection)
				{
					SectionBlock::model()->deleteAllByAttributes(array(
						'section_id' => $section->id
					));
				}

				// Add new content block selections
				$sectionBlocksData = array();
				foreach ($sectionBlockIds as $sortOrder => $blockId)
				{
					$sectionBlocksData[] = array($section->id, $blockId, false, $sortOrder+1);
				}
				Blocks::app()->db->createCommand()->insertAll('{{sectionblocks}}', array('section_id','block_id','required','sort_order'), $sectionBlocksData);

				$transaction->commit();
			}
			catch (Exception $e)
			{
				$transaction->rollBack();
				throw $e;
			}
		}

		return $section;
	}

	/**
	 * @param $sectionHandle
	 * @param $siteHandle
	 * @param $label
	 * @param null $urlFormat
	 * @param null $maxEntries
	 * @param null $template
	 * @param bool $sortable
	 * @param null $parentId
	 * @return Section
	 * @throws Exception
	 */
	public function createSection($sectionHandle, $siteHandle, $label, $urlFormat = null, $maxEntries = null, $template = null, $sortable = false, $parentId = null)
	{
		$connection = Blocks::app()->db;
		$site = Blocks::app()->sites->getSiteByHandle($siteHandle);

		$transaction = $connection->beginTransaction();
		try
		{
			$tableName = $this->_getEntryDataTableName($site->handle, $sectionHandle);

			// drop it if it exists
			if ($connection->schema->getTable('{{'.$tableName.'}}') !== null)
				$connection->createCommand()->dropTable('{{'.$tableName.'}}');

			// create dynamic data table
			$connection->createCommand()->createTable('{{'.$tableName.'}}',
				array('id'              => AttributeType::PK,
					  'entry_id'        => AttributeType::Integer.' NOT NULL',
					  'version_id'      => AttributeType::Integer.' NOT NULL',
					  'date_created'    => AttributeType::Integer,
					  'date_updated'    => AttributeType::Integer,
					  'uid'             => AttributeType::Varchar
				));

			$entriesFKName = strtolower($tableName.'_entries_fk');
			$connection->createCommand()->addForeignKey(
				$entriesFKName, '{{'.$tableName.'}}', 'entry_id', '{{entries}}', 'id', 'NO ACTION', 'NO ACTION'
			);

			$entryVersionsFKName = strtolower($tableName.'_entryversions_fk');
			$connection->createCommand()->addForeignKey(
				$entryVersionsFKName, '{{'.$tableName.'}}', 'version_id', '{{entryversions}}', 'id', 'NO ACTION', 'NO ACTION'
			);

			DatabaseHelper::createInsertAuditTrigger($tableName);
			DatabaseHelper::createUpdateAuditTrigger($tableName);

			// check result.
			$section = new Section();
			$section->sites->_id = $site->id;

			if ($parentId !== null)
				$section->parent_id = $parentId;

			$section->label = $label;
			$section->sortable = ($sortable == false ? 0 : 1);
			$section->handle = $sectionHandle;

			if ($urlFormat !== null)
				$section->url_format = $urlFormat;

			if ($maxEntries !== null)
				$section->max_entries = $maxEntries;

			if ($template !== null)
				$section->template = $template;

			$section->save();

			$transaction->commit();
			return $section;

		}
		catch (Exception $e)
		{
			$transaction->rollBack();
			throw $e;
		}
	}

	/* Blocks */

	/**
	 * @param $blockHandle
	 * @param $sectionHandle
	 * @param $siteHandle
	 * @param $label
	 * @param $type
	 * @param $sortOrder
	 * @param string $blockDataType
	 * @param null $instructions
	 * @param bool $required
	 * @return EntryBlocks
	 * @throws Exception
	 */
	public function createBlock($blockHandle, $sectionHandle, $siteHandle, $label, $type, $sortOrder, $blockDataType = AttributeType::Text, $instructions = null, $required = false)
	{
		$connection = Blocks::app()->db;
		$site = Blocks::app()->sites->getSiteByHandle($siteHandle);
		$section = $this->getSectionBySiteIdHandle($site->id, $sectionHandle);

		$transaction = $connection->beginTransaction();
		try
		{
			$tableName = $this->_getEntryDataTableName($site->handle, $sectionHandle);
			$lastBlockColumnName = $this->_getLastBlockColumnName($tableName);
			Blocks::app()->db->createCommand()->addColumnAfter(
				'{{'.$tableName.'}}',
				'block_'.$blockHandle,
				$blockDataType,
				$lastBlockColumnName
			);

			// add to entry block row to table.
			$block = new EntryBlocks();
			$block->section_id = $section->id;
			$block->handle = $blockHandle;
			$block->label = $label;
			$block->type = $type;
			$block->sort_order = $sortOrder;

			if ($instructions !== null)
				$block->instructions = $instructions;

			$block->required = ($required == false ? 0 : 1);
			$block->save();
			$transaction->commit();

			return $block;

		}
		catch (Exception $e)
		{
			$transaction->rollBack();
			throw $e;
		}
	}

	/**
	 * @param $sectionId
	 * @return mixed
	 */
	public function getBlocksBySectionId($sectionId)
	{
		$sections = SectionBlock::model()->findAllByAttributes(array(
			'section_id' => $sectionId,
		));

		return $sections;
	}

	/**
	 * @param $entryId
	 * @return array
	 */
	public function getBlocksByEntryId($entryId)
	{
		$blocks = Blocks::app()->db->createCommand()
			->select('eb.*')
			->from('{{entryblocks}} eb')
			->join('{{sections}} s', 's.id = eb.section_id')
			->join('{{entries}} e', 's.id = e.section_id')
			->where('e.id=:entryId', array(':entryId' => $entryId))
			->queryAll();

		return $blocks;
	}

	/**
	 * @param $entryId
	 * @param $handle
	 * @return array
	 */
	public function getBlockByEntryIdHandle($entryId, $handle)
	{
		$blocks = Blocks::app()->db->createCommand()
			->select('eb.*')
			->from('{{entryblocks}} eb')
			->join('{{sections}} s', 's.id = eb.section_id')
			->join('{{entries}} e', 's.id = e.section_id')
			->where('e.id=:entryId AND eb.handle=:handle', array(':entryId' => $entryId, ':handle' => $handle))
			->queryAll();

		return $blocks;
	}

	/**
	 * @param $siteHandle
	 * @param $sectionHandle
	 * @return string
	 */
	private function _getEntryDataTableName($siteHandle, $sectionHandle)
	{
		return strtolower('entrydata_'.$siteHandle.'_'.$sectionHandle);
	}

	/**
	 * @param $table
	 * @return null|string
	 */
	private function _getLastBlockColumnName($table)
	{
		Blocks::app()->db->schema->refresh();
		$dataTable = Blocks::app()->db->schema->getTable('{{'.$table.'}}');

		$columnNames = $dataTable->columnNames;

		$lastBlockMatch = null;
		foreach ($columnNames as $columnName)
		{
			if (strpos($columnName, 'block_') !== false)
				$lastBlockMatch = $columnName;
		}

		if ($lastBlockMatch == null)
			$lastBlockMatch = 'version_id';

		return $lastBlockMatch;
	}
}
