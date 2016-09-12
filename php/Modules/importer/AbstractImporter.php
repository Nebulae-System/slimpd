<?php
namespace Slimpd\Modules\importer;
/* Copyright
 *
 */
abstract class AbstractImporter {
	protected $batchUid;					// mysql batch record id
	protected $batchBegin;
	protected $jobUid;					// mysql record id
	protected $jobPhase;				// numeric index
	protected $jobBegin;				// tstamp
	protected $jobStatusInterval = 5; 	// seconds
	protected $lastJobStatusUpdate = 0; // timestamp

	// counters needed for calculating estimated time and speed [Tracks/minute]
	protected $itemsChecked = 0;
	protected $itemsProcessed = 0;
	protected $itemsTotal = 0;

	protected function beginJob($data = array(), $function = '') {
		cliLog("STARTING import phase " . $this->jobPhase . " " . $function . '()', 1, "cyan");
		$app = \Slim\Slim::getInstance();
		$this->jobBegin = getMicrotimeFloat();
		$this->itemsChecked = 0;
		$this->itemsProcessed = 0;
		
		$relPath = (isset($data['relPath']) === TRUE)
			? $app->db->real_escape_string($data['relPath'])
			: '';
		//$this->itemsTotal = 0;
		$query = "INSERT INTO importer
			(batchUid, jobPhase, jobStart, jobLastUpdate, jobStatistics, relPath)
			VALUES (
				". $this->getLastBatchUid().",
				".(int)$this->jobPhase.",
				". $this->jobBegin.",
				". $this->jobBegin. ",
				'" .serialize($data)."',
				'". $relPath ."')";
		$app->db->query($query);
		$this->jobUid = $app->db->insert_id;
		$this->lastJobStatusUpdate = $this->jobBegin;
		if($this->jobPhase !== 0) {
			return;
		}
		$query = "UPDATE importer SET batchUid='" .$this->jobUid."' WHERE uid=" . $this->jobUid;
		$app->db->query($query);
	}

	public function updateJob($data = array()) {
		$microtime = getMicrotimeFloat();
		if($microtime - $this->lastJobStatusUpdate < $this->jobStatusInterval) {
			return;
		}

		$data['progressPercent'] = 0;
		$data['microTimestamp'] = $microtime;
		$this->calculateSpeed($data);

		$query = "UPDATE importer
			SET jobStatistics='" .serialize($data)."',
			jobLastUpdate=".$microtime."
			WHERE uid=" . $this->jobUid;
		\Slim\Slim::getInstance()->db->query($query);
		cliLog('progress:' . $data['progressPercent'] . '%', 1);
		$this->lastJobStatusUpdate = $microtime;
		return;
	}

	protected function finishJob($data = array(), $function = '') {
		cliLog("FINISHED import phase " . $this->jobPhase . " " . $function . '()', 1, "cyan");
		$microtime = getMicrotimeFloat();
		$data['progressPercent'] = 100;
		$data['microTimestamp'] = $microtime;
		$this->calculateSpeed($data);

		$query = "UPDATE importer
			SET jobEnd=".$microtime.",
			jobLastUpdate=".$microtime.",
			jobStatistics='" .serialize($data)."' WHERE uid=" . $this->jobUid;
		
		\Slim\Slim::getInstance()->db->query($query);
		$this->jobUid = 0;
		$this->itemsChecked = 0;
		$this->itemsProcessed = 0;
		$this->itemsTotal = 0;
		$this->lastJobStatusUpdate = $microtime;
		return;
	}

	protected function calculateSpeed(&$data) {
		$data['itemsChecked'] = $this->itemsChecked;
		$data['itemsProcessed'] = $this->itemsProcessed;
		$data['itemsTotal'] = $this->itemsTotal;

		// this spped will be relevant for javascript animated progressbar
		$data['speedPercentPerSecond'] = 0;

		$data['runtimeSeconds'] = $data['microTimestamp'] - $this->jobBegin;
		if($this->itemsChecked < 1 || $this->itemsTotal <1) {
			return;
		}

		$seconds = getMicrotimeFloat() - $this->jobBegin;

		$itemsPerMinute = $this->itemsChecked/$seconds*60;
		$data['speedItemsPerMinute'] = floor($itemsPerMinute);
		$data['speedItemsPerHour'] = floor($itemsPerMinute*60);
		$data['speedPercentPerSecond'] = ($itemsPerMinute/60)/($this->itemsTotal/100);

		$minutesRemaining = ($this->itemsTotal - $this->itemsChecked) / $itemsPerMinute;
		if($data['progressPercent'] !== 0) {
			$data['estimatedRemainingSeconds'] = 0;
			$data['estimatedTotalRuntime'] = $data['runtimeSeconds'];
			return;
		}

		$data['progressPercent'] = floor($this->itemsChecked / ($this->itemsTotal/100));
		// make sure we don not display 100% in case it is not finished
		$data['progressPercent'] = ($data['progressPercent']>99) ? 99 : $data['progressPercent'];

		$data['estimatedRemainingSeconds'] = round($minutesRemaining*60);
		$data['estimatedTotalRuntime'] = round($this->itemsTotal/$itemsPerMinute*60);
	}

	protected function getLastBatchUid() {
		$query = "SELECT uid FROM importer WHERE jobPhase = 0 ORDER BY uid DESC LIMIT 1;";
		$batchUid = \Slim\Slim::getInstance()->db->query($query)->fetch_assoc()['uid'];
		if($batchUid !== NULL) {
			return $batchUid;
		}
		return 0;
	}

	public function setItemsTotal($value) {
		$this->itemsTotal = $value;
		return $this;
	}

	public function setItemsChecked($value) {
		$this->itemsChecked = $value;
		return $this;
	}

	public function setItemsProcessed($value) {
		$this->itemsProcessed = $value;
		return $this;
	}
}
