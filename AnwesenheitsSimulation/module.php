<?

	$resultLink = array();
class AnwesenheitsSimulation extends IPSModule
{

	public function Create() {
		//Never delete this line!
		parent::Create();

		//These lines are parsed on Symcon Startup or Instance creation
		//You cannot use variables here. Just static values.
		$this->RegisterPropertyInteger("RequiredSwitchCount", 4);
		$this->RegisterPropertyInteger("PreviewName", "O");
		$this->RegisterPropertyInteger("ArchiveControlID", IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0]);

		//Timer
		$this->RegisterMidnightTimer("UpdateDataTimer", 'if(AS_UpdateData($_IPS[\'TARGET\'])) {AS_UpdateTargets($_IPS[\'TARGET\']);}');
		$this->RegisterTimer("UpdateTargetsTimer", 0, 'AS_UpdateTargets($_IPS[\'TARGET\']);');

		//Variables
		$this->RegisterVariableString("SimulationData", "SimulationData", "");
		IPS_SetHidden($this->GetIDForIdent("SimulationData"), true);
		$this->RegisterVariableString("SimulationView", "Simulationsvorschau", "~HTMLBox");
		$this->RegisterVariableString("SimulationDay", "Simulationsquelle (Tag)", "");
		$this->RegisterVariableBoolean("Active", "Simulation aktiv", "~Switch");
		$this->EnableAction("Active");

		$this->CreateCategoryByIdent($this->InstanceID, "Targets", "Targets (Simulation)");

	}

	public function Destroy() {
		//Never delete this line!
		parent::Destroy();

	}

	public function ApplyChanges() {
		//Never delete this line!
		parent::ApplyChanges();

	}

	public function SetSimulation(bool $SwitchOn){

		if ($SwitchOn){
			//When activating the simulation, fetch actual data for a day and activate timer for updating targets
			if($this->UpdateData()) {
				$this->UpdateTargets();
				IPS_SetEventActive($this->GetIDForIdent("UpdateDataTimer"), true);
				IPS_SetHidden($this->GetIDForIdent("SimulationView"), false);
			}
		} else {
			//When deactivating the simulation, kill data for simulation and deactivate timer for updating targets
			SetValue($this->GetIDForIdent("SimulationDay"), "Simulation deaktiviert");
			SetValue($this->GetIDForIdent("SimulationData"), "");
			$this->SetTimerInterval("UpdateTargetsTimer", 0);
			IPS_SetEventActive($this->GetIDForIdent("UpdateDataTimer"), false);
			SetValue($this->GetIDForIdent("SimulationView"), "Simulation deaktiviert");
			IPS_SetHidden($this->GetIDForIdent("SimulationView"), true);
		}

		SetValue($this->GetIDForIdent("Active"), $SwitchOn);

	}

	public function RequestAction($Ident, $Value) {

		switch($Ident) {
			case "Active":
				$this->SetSimulation($Value);
				break;
			default:
				throw new Exception("Invalid ident");
		}

	}

	//Returns all "real" variableID's as array, which are linked in the "Targets" category
	private function GetTargets() {

		$targetIDs = IPS_GetChildrenIDs(IPS_GetObjectIDByIdent("Targets", $this->InstanceID));

		$result = array();

		$i = 0;
		foreach($targetIDs as $targetID) {
			//Only allow links
			if (IPS_LinkExists($targetID)) {
				if (IPS_VariableExists(IPS_GetLink($targetID)['TargetID']))
				//$result[$i]['TargetID'] = IPS_GetLink($targetID)['TargetID'];
				//$result[$i]['LinkID'] = $targetID; 
				$result[$i]['TargetID'] = IPS_GetLink($targetID)['TargetID'];
				$result[$i]['LinkID'] = $targetID;
				$i++;
			}
		}
		//print_r($result);
		return $result;
	}

	//returns a array of the dayData of 1 Variable
	private function GetDayData($day, $targetIDs) {
		$dayStart = mktime(0, 0, 0, date("m"), date("d"), date("Y"));
		$dayDiff = $day * 24 * 3600;
		$dayData = array();
		$values = array();

		//Going through all linked variables
		foreach($targetIDs as $targetID) {
			if (AC_GetLoggingStatus($this->ReadPropertyInteger("ArchiveControlID"), $targetID['TargetID'])) {
				//Fetch Data for all variables but only one day
				$values = AC_GetLoggedValues($this->ReadPropertyInteger("ArchiveControlID"), $targetID['TargetID'], $dayStart - $dayDiff, $dayStart + (24 * 3600) - $dayDiff - 1, 0);
				if (sizeof($values) > 0){

					//Transform UnixTimeStamp into human readable value
					foreach($values as $key => $value){
						$values[$key]['TimeStamp'] = date("H:i:s", $value['TimeStamp']);
					}

					//Reverse array to have the Timestamps ascending
					$dayData[$targetID['TargetID']] = array_reverse($values);
				}
			}
		}

		// return all values for linked variables for one day in a array
		if (sizeof($values) > 0){
			return array("Date" => date("d.m.Y", $dayStart - $dayDiff), "Data" => $dayData);
		}

	}

	//returns a array of all linked variables for 1 day and checks if this meets the needed switchcount
	private function GetDataArray($days, $targetIDs) {

		//Get the dayData for all variables
		foreach ($days as $day) {
			$data = $this->GetDayData($day, $targetIDs);

			$this->SendDebug("Fetch", "Fetched day -".$day." with ".sizeof($data['Data'])." valid device(s)", 0);

			if (sizeof($data['Data']) > 0) {

				//Sum up the switchCount
				$switchCounts = 0;
				foreach ($data['Data'] as $value){
					$switchCounts += sizeof($value);
				}

				$this->SendDebug("Fetch", "> Required entropy of ".($this->ReadPropertyInteger("RequiredSwitchCount") * sizeof($targetIDs)).". Have ".$switchCounts, 0);

				//Check if the needed switchCount requierement is meet
				if ($switchCounts >= ($this->ReadPropertyInteger("RequiredSwitchCount") * sizeof($targetIDs))){
					return $data;
				}

			}
		}

		return array();

	}

	//Fetches the needed SimulationData for a whole day
	public function UpdateData() {
		$targetIDs = $this->GetTargets();
		
		//Tries to fetch data for a random but same weekday for the last 4 weeks
		$weekDays = array(7, 14, 21, 28);
		shuffle($weekDays);

		//If no same weekday possible -> fetch 1 out of the last 30 days (but not the last 4 weeks)
		$singleDays = array_diff(range(1, 30), $weekDays);
		shuffle($singleDays);

		$simulationData = $this->GetDataArray(array_merge($weekDays, $singleDays), $targetIDs);
		if(sizeof($simulationData) == 0) {
			SetValue($this->GetIDForIdent("SimulationDay"), "Zu wenig Daten!");
		} else {
			SetValue($this->GetIDForIdent("SimulationDay"), $simulationData['Date']);
			SetValue($this->GetIDForIdent("SimulationData"), wddx_serialize_value($simulationData['Data']));
		}

		return sizeof($simulationData) > 0;

	}

	public function GetNextSimulationData() {

		$simulationData = wddx_deserialize(GetValueString(IPS_GetObjectIDByIdent("SimulationData", $this->InstanceID)));
		$nextSwitchTimestamp = PHP_INT_MAX;
		$result = array();

		//Being sure there is simulationData
		if($simulationData !== NULL && $simulationData != "") {
			//Going through all variableID's of the simulationData
			foreach($simulationData as $id => $value) {
				if (IPS_VariableExists($id)) {
					unset($currentValue);
					unset($currentTime);
					unset($nextValue);
					unset($nextTime);

					//Getting the value to set
					foreach ($value as $key) {
						if (date("H:i:s") > $key["TimeStamp"]) {
							$currentValue = $key["Value"];
							$currentTime = $key["TimeStamp"];
						} else {
							$nextValue = $key["Value"];
							$nextTime = $key["TimeStamp"];
							
							$nextSwitchTimestamp = min($nextSwitchTimestamp, strtotime($key["TimeStamp"]));
							break;
						}
					}

					if (!isset($currentValue) || !isset($currentTime)) {
						$currentValue = false;
						$currentTime = "00:00";
					}
					if (!isset($nextValue) || !isset($nextTime)) {
						$nextValue = "-";
						$nextTime = "-";
					}
					
					$result[$id] = array("currentValue" => $currentValue, "currentTime" => $currentTime, "nextValue" => $nextValue, "nextTime" => $nextTime);

				}
			}
		} else {
			echo "No valid SimulationData";
		}

		if($nextSwitchTimestamp != PHP_INT_MAX) {
			$result["nextSwitchTimestamp"] = $nextSwitchTimestamp;
		}

		return $result;
	}


	public function UpdateTargets() {

		$targetIDs = $this->GetTargets();
		$NextSimulationData = $this->GetNextSimulationData();

		//lets update the preview table
		$this->UpdateView($targetIDs, $NextSimulationData);

		foreach ($targetIDs as $targetID){
			$targetID = $targetID['TargetID'];
			$v = IPS_GetVariable($targetID);

			if(!isset($NextSimulationData[$targetID])) {
				$this->SendDebug("Update", "Device ".$targetID." has no simulation data for now!", 0);

				$targetValue = false;
			} else {
				$this->SendDebug("Update", "Device ".$targetID." shall be ".(int)$NextSimulationData[$targetID]['currentValue']." since ".$NextSimulationData[$targetID]['currentTime']." and currently is ".(int)$v["VariableValue"], 0);

				//Set variableValue, if there is a currentValue and its not the same as already set
				$targetValue = $NextSimulationData[$targetID]['currentValue'];
			}

			if ($targetValue != $v["VariableValue"]) {

				$o = IPS_GetObject($targetID);
				if($v['VariableCustomAction'] != "") {
					$actionID = $v['VariableCustomAction'];
				} else {
					$actionID = $v['VariableAction'];
				}

				$this->SendDebug("Action", "Device ".$targetID." will be updated!", 0);

				if(IPS_InstanceExists($actionID)) {
					IPS_RequestAction($actionID, $o['ObjectIdent'], $targetValue);
				} else if(IPS_ScriptExists($actionID)) {
					echo IPS_RunScriptWaitEx($actionID, Array("VARIABLE" => $targetID, "VALUE" => $targetValue));
				}

			}
		}

		if(isset($NextSimulationData['nextSwitchTimestamp'])) {
			$this->SetTimerInterval("UpdateTargetsTimer", ($NextSimulationData['nextSwitchTimestamp'] - time() + 1) * 1000);
		} else {
			$this->SetTimerInterval("UpdateTargetsTimer", 0);
		}


	}

	private function UpdateView($targetIDs, $nextSimulationData) {
	
		$html = "<table style='width: 100%; border-collapse: collapse;'>";
		$html .= "<tr>";
		$html .= "<td style='padding: 5px; font-weight: bold;'>Aktor</td>";
		$html .= "<td style='padding: 5px; font-weight: bold;'>Letzter Wert</td>";
		$html .= "<td style='padding: 5px; font-weight: bold;'>Seit</td>";
		$html .= "<td style='padding: 5px; font-weight: bold;'>Nächster Wert</td>";
		$html .= "<td style='padding: 5px; font-weight: bold;'>Um</td>";
		$html .= "</tr>";

		foreach ($targetIDs as $targetID) {
			$LinkID = $targetID['LinkID'];
			$targetID = $targetID['TargetID'];
			$html .= "<tr style='border-top: 1px solid rgba(255,255,255,0.10);'>";
			if($this->ReadPropertyInteger("PreviewName") == 0)
			{
				$html .= "<td style='padding: 5px;'>".IPS_GetName($LinkID)."</td>";
			} else {
				$html .= "<td style='padding: 5px;'>".IPS_GetName(IPS_GetParent($targetID))."\\".IPS_GetName($targetID)."</td>";
			}

			if(isset($nextSimulationData[$targetID])) {
				$html .= "<td style='padding: 5px;'>".(int)$nextSimulationData[$targetID]["currentValue"]."</td>";
				$html .= "<td style='padding: 5px;'>".$nextSimulationData[$targetID]["currentTime"]."</td>";
				$html .= "<td style='padding: 5px;'>".(int)$nextSimulationData[$targetID]["nextValue"]."</td>";
				$html .= "<td style='padding: 5px;'>".$nextSimulationData[$targetID]["nextTime"]."</td>";
			} else {
				$html .= "<td style='padding: 5px;'>0</td>";
				$html .= "<td style='padding: 5px;'>00:00</td>";
				$html .= "<td style='padding: 5px;'>-</td>";
				$html .= "<td style='padding: 5px;'>-</td>";
			}
			$html .= "</tr>";
		}

		$html .= "</table>";

		SetValue($this->GetIDForIdent("SimulationView"), $html);

	}

	private function CreateCategoryByIdent($id, $ident, $name) {
		 $cid = @IPS_GetObjectIDByIdent($ident, $id);
		 if($cid === false)
		 {
			 $cid = IPS_CreateCategory();
			 IPS_SetParent($cid, $id);
			 IPS_SetName($cid, $name);
			 IPS_SetIdent($cid, $ident);
		 }
		 return $cid;
	}

	private function RegisterMidnightTimer($Ident, $Action) {

		//search for already available scripts with proper ident
		$eid = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);

		//properly update eventID
		if($eid === false) {
			$eid = 0;
		} else if(IPS_GetEvent($eid)['EventType'] <> 1) {
			IPS_DeleteEvent($eid);
			$eid = 0;
		}

		//we need to create one
		if ($eid == 0) {
			$eid = IPS_CreateEvent(1);
			IPS_SetParent($eid, $this->InstanceID);
			IPS_SetIdent($eid, $Ident);
			IPS_SetName($eid, $Ident);
			IPS_SetHidden($eid, true);
			IPS_SetEventScript($eid, $Action);
		}

		IPS_SetEventCyclic($eid, 2, 1, 0, 0, 0, 0);
		IPS_SetEventCyclicTimeFrom($eid, 0, 0, 1);

	}

}
?>
