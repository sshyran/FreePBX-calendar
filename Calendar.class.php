<?php
namespace FreePBX\modules;
use \Moment\Moment;
use \Moment\CustomFormats\MomentJs;
use \Ramsey\Uuid\Uuid;
use \Ramsey\Uuid\Exception\UnsatisfiedDependencyException;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use it\thecsea\simple_caldav_client\SimpleCalDAVClient;
use om\IcalParser;
use Eluceo\iCal\Component\Calendar as iCalendar;
use Eluceo\iCal\Component\Event;
use Eluceo\iCal\Property\Event\RecurrenceRule;

include __DIR__."/vendor/autoload.php";

class Calendar extends \DB_Helper implements \BMO {
	private $now; //right now, private so it doesnt keep updating

	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new Exception("Not given a FreePBX Object");
		}
		$this->now = Carbon::now();
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->systemtz = $this->FreePBX->View()->getTimezone();
		$this->eventDefaults = array(
				'uid' => '',
				'user' => '',
				'description' => '',
				'hookdata' => '',
				'active' => true,
				'generatehint' => false,
				'generatefc' => false,
				'eventtype' => 'calendaronly',
				'weekdays' => '',
				'monthdays' => '',
				'months' => '',
				'timezone' => $this->systemtz,
				'startdate' => '',
				'enddate' => '',
				'starttime' => '',
				'endtime' => '',
				'repeatinterval' => '',
				'frequency' => '',
				'truedest' => '',
				'falsedest' => ''
			);
	}

	public function setTimezone($timezone) {
		$this->systemtz = $timezone;
		$this->now = Carbon::now($this->systemtz);
	}

	public function backup() {}
	public function restore($backup) {}
  public function install(){}
  public function uninstall(){}
	public function doConfigPageInit($page) {
		switch ($page) {
			case 'calendar':
				$action = isset($_REQUEST['action'])?$_REQUEST['action']:'';
				switch($action) {
					case "add":
						if(isset($_POST['name'])) {
							$name = $_POST['name'];
							$description = $_POST['description'];
							$type = $_POST['type'];
							switch($type) {
								case "ical":
									$url = $_POST['url'];
									$this->addRemoteiCalCalendar($name,$description,$url);
								break;
								case "google":
								break;
								case "caldav":
									$purl = $_POST['purl'];
									$surl = $_POST['surl'];
									$username = $_POST['username'];
									$password = $_POST['password'];
									$calendars = $_POST['calendars'];
									$this->addRemoteCalDavCalendar($name,$description,$purl,$surl,$username,$password,$calendars);
								break;
								case "outlook":
								break;
								case "local":
									$this->addLocalCalendar($name,$description);
								break;
							}
						}
					break;
					case "edit":
						if(isset($_POST['name'])) {
							$name = $_POST['name'];
							$description = $_POST['description'];
							$type = $_POST['type'];
							$id = $_POST['id'];
							switch($type) {
								case "ical":
									$url = $_POST['url'];
									$this->updateRemoteiCalCalendar($id,$name,$description,$url);
								break;
								case "google":
								break;
								case "caldav":
									$purl = $_POST['purl'];
									$surl = $_POST['surl'];
									$username = $_POST['username'];
									$password = $_POST['password'];
									$calendars = $_POST['calendars'];
									$this->updateRemoteCalDavCalendar($id,$name,$description,$purl,$surl,$username,$password,$calendars);
								break;
								case "outlook":
								break;
								case "local":
									$timezone = $_POST['timezone'];
									$this->updateLocalCalendar($id,$name,$description,$timezone);
								break;
							}
						}
					break;
					case "delete":
						$this->delCalendarByID($_REQUEST['id']);
					break;
				}
			break;
			case 'calendargroups':
				$action = isset($_REQUEST['action'])?$_REQUEST['action']:'';
				$description = isset($_REQUEST['description'])?$_REQUEST['description']:'';
				$events = isset($_REQUEST['events'])?$_REQUEST['events']:array();
				switch($action) {
					case "add":
						if(isset($_POST['name'])) {
							$name = !empty($_POST['name']) ? $_POST['name'] : array();
							$calendars = !empty($_POST['calendars']) ? $_POST['calendars'] : array();
							$categories = !empty($_POST['categories']) ? $_POST['categories'] : array();
							$events = !empty($_POST['events']) ? $_POST['events'] : array();
							$this->addGroup($name,$calendars,$categories,$events);
						}
					break;
					case "edit":
						if(isset($_POST['name'])) {
							$id = $_POST['id'];
							$name = !empty($_POST['name']) ? $_POST['name'] : array();
							$calendars = !empty($_POST['calendars']) ? $_POST['calendars'] : array();
							$categories = !empty($_POST['categories']) ? $_POST['categories'] : array();
							$events = !empty($_POST['events']) ? $_POST['events'] : array();
							$this->updateGroup($id,$name,$calendars,$categories,$events);
						}
					break;
					case "delete":
						$id = $_GET['id'];
						$this->deleteGroup($id);
					break;
				}
			break;
		}
	}
	public function ajaxRequest($req, &$setting) {
		switch($req){
			case 'grid':
			case 'events':
			case 'eventform':
			case 'delevent':
			case 'groupsgrid':
			case 'groupeventshtml':
			case 'getcaldavcals':
				return true;
			break;
			default:
				return false;
			break;
		}
	}
	public function ajaxHandler() {
		switch ($_REQUEST['command']) {
			case 'getcaldavcals':
				$caldavClient = new SimpleCalDAVClient();
				$caldavClient->connect($_POST['purl'], $_POST['username'], $_POST['password']);
				$calendars = $caldavClient->findCalendars();
				$chtml = '';
				foreach($calendars as $calendar) {
					$chtml .= '<option value="'.$calendar->getCalendarID().'">'.$calendar->getDisplayName().'</option>';
				}
				return array("calshtml" => $chtml);
			break;
			case 'groupeventshtml':
				$allCalendars = $this->listCalendars();
				$calendars = !empty($_POST['calendars']) ? $_POST['calendars'] : array();
				$dcategories = !empty($_POST['categories']) ? $_POST['categories'] : array();
				$categories = array();
				foreach($dcategories as $cat) {
					$parts = explode("_",$cat,2);
					$categories[$parts[0]][] = $parts[1];
				}
				$chtml = '';
				foreach($calendars as $calendarID) {
					$cats = $this->getCategoriesByCalendarID($calendarID);
					$chtml .= '<optgroup label="'.$allCalendars[$calendarID]['name'].'">';
					foreach($cats as $name => $events) {
						$chtml .= '<option value="'.$calendarID.'_'.$name.'">'.$name.'</option>';
					}
					$chtml .= '</optgroup>';
				}
				$ehtml = '';
				foreach($calendars as $calendarID) {
					$events = $this->listEvents($calendarID);
					if(!empty($categories[$calendarID])) {
						$valid = array();
						$cats = $this->getCategoriesByCalendarID($calendarID);
						foreach($cats as $category => $evts) {
							if(in_array($category,$categories[$calendarID])) {
								$evts = array_flip($evts);
								$valid = array_merge($valid,$evts);
							}
						}
						$events = array_intersect_key($events,$valid);
					} elseif(!empty($categories)) {
						$events = array();
					}
					$ehtml .= '<optgroup label="'.$allCalendars[$calendarID]['name'].'">';
					foreach($events as $event) {
						$extended = $event['allDay'] ? $event['startdate'] : $event['startdate'].' '._('to').' '.$event['enddate'];
						$ehtml .= '<option value="'.$calendarID.'_'.$event['uid'].'">'.$event['name'].' ('.$extended.')</option>';
					}
					$ehtml .= '</optgroup>';
				}
				return array("eventshtml" => $ehtml, "categorieshtml" => $chtml);
			break;
			case 'delevent':
				$calendarID = $_POST['calendarid'];
				$eventID = $_POST['eventid'];
				$this->deleteEvent($calendarID,$eventID);
			break;
			case 'grid':
				$calendars = $this->listCalendars();
				$final = array();
				foreach($calendars as $id => $data) {
					$data['id'] = $id;
					$final[] = $data;
				}
				return $final;
			break;
			case 'events':
				$start = new Carbon($_GET['start'],$_GET['timezone']);
				$end = new Carbon($_GET['end'],$_GET['timezone']);
				$events = $this->listEvents($_REQUEST['calendarid'],$start, $end);
				$events = is_array($events) ? $events : array();
				return array_values($events);
			break;
			case 'eventform':
				$calendarID = $_POST['calendarid'];
				$calendar = $this->getCalendarByID($calendarID);

				$timezone = !empty($_POST['timezone']) ? $_POST['timezone'] : $calendar['timezone'];
				$vCalendar = new iCalendar($calendarID);
				$vEvent = new Event();
				$vEvent->setUseTimezone(true);
				$vEvent->setSummary($_POST['title']);
				$vEvent->setDescription($_POST['description']);
				$vEvent->setDtStart(new Carbon($_POST['startdate']." ".$_POST['starttime'], $timezone));
				$vEvent->setDtEnd(new Carbon($_POST['enddate']." ".$_POST['endtime'], $timezone));
				if(!empty($_REQUEST['allday']) && $_REQUEST['allday'] == "yes") {
					$vEvent->setDtStart(new Carbon($_POST['startdate'], $timezone));
					$vEvent->setDtEnd(new Carbon($_POST['enddate'], $timezone));
				}
				if(!empty($_REQUEST['reoccurring']) && $_REQUEST['reoccurring'] == "yes") {
					if(!empty($_POST['rstartdate'])) {
						$vEvent->setDtStart(Carbon::createFromTimestamp($_POST['rstartdate'], $timezone));
						$vEvent->setDtStart(Carbon::createFromTimestamp($_POST['renddate'], $timezone));
					}
					$recurrenceRule = new RecurrenceRule();
					switch($_REQUEST['repeats']) {
						case "0":
							$recurrenceRule->setFreq(RecurrenceRule::FREQ_DAILY);
						break;
						case "1":
							$recurrenceRule->setByDay("MO,TU,WE,TH,FR");
							$recurrenceRule->setFreq(RecurrenceRule::FREQ_WEEKLY);
						break;
						case "2":
							$recurrenceRule->setByDay("MO,WE,FR");
							$recurrenceRule->setFreq(RecurrenceRule::FREQ_WEEKLY);
						break;
						case "3":
							$recurrenceRule->setByDay("TU,TH");
							$recurrenceRule->setFreq(RecurrenceRule::FREQ_WEEKLY);
						break;
						case "4":
							if(!empty($_REQUEST['weekday']) && is_array($_REQUEST['weekday'])) {
								$days = array();
								foreach($_REQUEST['weekday'] as $day) {
									switch($day) {
										case "0":
											$days[] = 'MO';
										break;
										case "1":
											$days[] = 'TU';
										break;
										case "2":
											$days[] = 'WE';
										break;
										case "3":
											$days[] = 'TH';
										break;
										case "4":
											$days[] = 'FR';
										break;
										case "5":
											$days[] = 'SA';
										break;
										case "6":
											$days[] = 'SU';
										break;
										default:
										break;
									}
								}
								$recurrenceRule->setByDay(implode(",",$days));
							}
							$recurrenceRule->setFreq(RecurrenceRule::FREQ_WEEKLY);
						break;
						case "5":
							$recurrenceRule->setFreq(RecurrenceRule::FREQ_MONTHLY);
						break;
						case "6":
							$recurrenceRule->setFreq(RecurrenceRule::FREQ_YEARLY);
						break;
						default:
						break;
					}
					if(!empty($_REQUEST['repeat-count'])) {
						$recurrenceRule->setInterval($_REQUEST['repeat-count']);
					}
					if(!empty($_REQUEST['occurrences'])) {
						$recurrenceRule->setCount($_REQUEST['occurrences']);
					}
					if(!empty($_REQUEST['afterdate'])) {
						$recurrenceRule->setUntil(new Carbon($_POST['afterdate'], $timezone));
					}

					$vEvent->setRecurrenceRule($recurrenceRule);
				}
				$uuid = ($_REQUEST['eventid'] == 'new') ? (string)Uuid::uuid4() : $_REQUEST['eventid'];
				$vEvent->setUniqueId($uuid);

				$vCalendar->addComponent($vEvent);

				$cal = new IcalParser();
				$render = $vCalendar->render();
				$render = str_replace('"','',$render); //TODO: bad
				$cal->parseString($render);
				$this->deleteEvent($calendarID,$uuid); //TODO this is strange
				foreach($cal->getSortedEvents() as $event) {
					$this->processiCalEvent($calendarID, $event);
				}
			break;
			case 'groupsgrid':
				$groups =  $this->listGroups();
				$final = array();
				foreach($groups as $id => $data) {
					$data['id'] = $id;
					$final[] = $data;
				}
				return $final;
			break;
    }
  }

	public function showCalendarGroupsPage() {
		$action = !empty($_GET['action']) ? $_GET['action'] : '';
		switch($action) {
			case "add":
				$calendars = $this->listCalendars();
				return load_view(__DIR__."/views/calendargroups.php",array("calendars" => $calendars, "action" => _("Add")));
			break;
			case "edit":
				$calendars = $this->listCalendars();
				$group = $this->getGroup($_REQUEST['id']);
				return load_view(__DIR__."/views/calendargroups.php",array("calendars" => $calendars, "group" => $group, "action" => _("Edit")));
			break;
			case "view":
			break;
			default:
				return load_view(__DIR__."/views/calendargroupgrid.php",array());
			break;
		}
	}

	public function showCalendarPage() {
		$action = !empty($_GET['action']) ? $_GET['action'] : '';
		switch($action) {
			case "add":
				$type = !empty($_GET['type']) ? $_GET['type'] : '';
				switch($type) {
					case "ical":
						return load_view(__DIR__."/views/remote_ical_settings.php",array('action' => 'add', 'type' => $type));
					break;
					case "caldav":
						return load_view(__DIR__."/views/remote_caldav_settings.php",array('action' => 'add', 'type' => $type));
					break;
					case "outlook":
					case "google":
					break;
					case "local":
						return load_view(__DIR__."/views/local_settings.php",array('action' => 'add', 'type' => $type, 'timezone' => $this->systemtz));
					break;
				}
			break;
			case "edit":
				$data = $this->getCalendarByID($_GET['id']);
				switch($data['type']) {
					case "ical":
						return load_view(__DIR__."/views/remote_ical_settings.php",array('action' => 'edit', 'type' => $data['type'], 'data' => $data));
					break;
					case "caldav":
						$caldavClient = new SimpleCalDAVClient();
						$caldavClient->connect($data['purl'], $data['username'], $data['password']);
						$cals = $caldavClient->findCalendars();
						$calendars = array();
						foreach($cals as $calendar) {
							$id = $calendar->getCalendarID();
							$calendars[$id] = array(
								"id" => $id,
								"name" => $calendar->getDisplayName(),
								"selected" => in_array($id,$data['calendars'])
							);
						}
						return load_view(__DIR__."/views/remote_caldav_settings.php",array('action' => 'edit', 'type' => $data['type'], 'data' => $data, 'calendars' => $calendars));
					break;
					case "outlook":
					case "google":
					break;
					case "local":
						return load_view(__DIR__."/views/local_settings.php",array('action' => 'edit', 'type' => $data['type'], 'data' => $data, 'timezone' => $data['timezone']));
					break;
				}
			break;
			case "view":
				$data = $this->getCalendarByID($_GET['id']);
				\Moment\Moment::setLocale('en_US');
				$locale = \Moment\MomentLocale::getLocaleContent();
				return load_view(__DIR__."/views/calendar.php",array('action' => 'view', 'type' => $data['type'], 'data' => $data, 'locale' => $locale));
			break;
			default:
				return load_view(__DIR__."/views/grid.php",array());
			break;
		}
	}

	/**
	 * Get Event by Event ID
	 * @param  string $calendarID The calendar ID
	 * @param  string $id The event ID
	 * @return array     The returned event array
	 */
	public function getEvent($calendarID,$eventID) {
		$events = $this->getAll($calendarID.'-events');
		return isset($events[$eventID]) ? $events[$eventID] : false;
	}

	/**
	 * List Calendars
	 * @return array The returned calendar array
	 */
	public function listCalendars() {
		$calendars = $this->getAll('calendars');
		return $calendars;
	}

	/**
	 * Delete Calendar by ID
	 * @param  string $id The calendar ID
	 */
	public function delCalendarByID($id) {
		$this->setConfig($id,false,'calendars');
		$this->delById($id."-events");
		$this->delById($id."-linked-events");
		$this->delById($id."-categories-events");
	}

	/**
	 * Get Calendar by ID
	 * @param  string $id The Calendar ID
	 * @return array     Calendar data
	 */
	public function getCalendarByID($id) {
		$final = $this->getConfig($id,'calendars');
		$final['id'] = $id;
		return $final;
	}

	/**
	 * Expand Recurring Days
	 * @param  string $id    Event ID
	 * @param  array $event Array of event information
	 * @return array        Array of Event information
	 */
	public function expandRecurring($id, $event) {
		if(!$event['recurring']) {
			$event['linkedid'] = $id;
			$event['uid'] = $id;
			$tmp['rstartdate'] = '';
			return array($event['uid'] => $event);
		}
		$final = array();
		$i = 0;
		$startdate = null;
		$enddate = null;
		foreach($event['events'] as $evt) {
			$tmp = $event;
			unset($tmp['events']);
			//TODO: This is ugly, work on it later
			$tmp['starttime'] = $evt['starttime'];
			$tmp['endtime'] = $evt['endtime'];
			$tmp['linkedid'] = $id;
			$tmp['uid'] = $id."_".$i;
			if($i == 0){
				$startdate = $tmp['starttime'];
				$enddate = $tmp['endtime'];
			}
			$tmp['rstartdate'] = $startdate;
			$tmp['renddate'] = $enddate;
			$final[$tmp['uid']] = $tmp;
			$i++;
		}

		return $final;
	}

	/**
	 * List Events
	 * @param  string $calendarID The calendarID to reference
	 * @param  object $start  Carbon Object
	 * @param  object $stop   Carbon Object
	 * @param  bool $subevents Break date ranges in to daily events.
	 * @return array  an array of events
	 */
	public function listEvents($calendarID, $start = null, $stop = null, $subevents = false) {
		$return = array();
		$calendar = $this->getCalendarByID($calendarID);
		$data = $this->getAll($calendarID.'-events');
		$events = array();
		foreach($data as $id => $event) {
			$d = $this->expandRecurring($id, $event);
			$events = array_merge($events,$d);
		}

		if(!empty($start) && !empty($stop)){
			$events = $this->eventFilterDates($events, $start, $stop, $calendar['timezone']);
		}

		foreach($events as $uid => $event){
			$starttime = !empty($event['starttime'])?$event['starttime']:'00:00:00';
			$endtime = !empty($event['endtime'])?$event['endtime']:'23:59:59';
			$event['ustarttime'] = $event['starttime'];
			$event['uendtime'] = $event['endtime'];
			$event['title'] = $event['name'];
			$event['uid'] = $uid;
			if(($event['starttime'] != $event['endtime']) && $subevents) {
				$startrange = Carbon::createFromTimeStamp($event['starttime'],$calendar['timezone']);
				$endrange = Carbon::createFromTimeStamp($event['endtime'],$calendar['timezone']);
				$daterange = new \DatePeriod($startrange, CarbonInterval::day(), $endrange);
				$i = 0;
				foreach($daterange as $d) {
					$tempevent = $event;
					$tempevent['uid'] = $uid.'_'.$i;
					$tempevent['ustarttime'] = $event['starttime'];
					$tempevent['uendtime'] = $event['endtime'];
					$tempevent['startdate'] = $d->format('Y-m-d');
					$tempevent['enddate'] = $d->format('Y-m-d');
					$tempevent['starttime'] = $d->format('H:i:s');
					$tempevent['endtime'] = $d->format('H:i:s');
					$tempevent['start'] = sprintf('%sT%s',$tempevent['startdate'],$tempevent['starttime']);
					$tempevent['end'] = sprintf('%sT%s',$tempevent['enddate'],$tempevent['endtime']);
					$tempevent['allDay'] = ($event['endtime'] - $event['starttime']) === 86400;
					//$tempevent['now'] = $this->now->between($start, $end);
					$tempevent['parent'] = $event;
					$return[$tempevent['uid']] = $tempevent;
					$i++;
				}
			}else{
				$event['ustarttime'] = $event['starttime'];
				$event['uendtime'] = $event['endtime'];

				$start = Carbon::createFromTimeStamp($event['ustarttime'],$calendar['timezone']);
				if($event['starttime'] == $event['endtime']) {
					$event['allDay'] = true;
					$end = $start->copy()->addDay();
				} else {
					$event['allDay'] = ($event['endtime'] - $event['starttime']) === 86400;
					$end = Carbon::createFromTimeStamp($event['uendtime'],$calendar['timezone']);
				}

				$event['uid'] = $uid;
				$event['startdate'] = $start->format('Y-m-d');
				$event['enddate'] = $end->format('Y-m-d');
				$event['starttime'] = $start->format('H:i:s');
				$event['endtime'] = $end->format('H:i:s');
				$event['start'] = sprintf('%sT%s',$event['startdate'],$event['starttime']);
				$event['end'] = sprintf('%sT%s',$event['enddate'],$event['endtime']);
				$event['now'] = $this->now->between($start, $end);

				$return[$uid] = $event;
			}
		}
		uasort($return, function($a, $b) {
			if ($a['ustarttime'] == $b['ustarttime']) {
				return 0;
			}
			return ($a['ustarttime'] < $b['ustarttime']) ? -1 : 1;
		});
		return $return;
	}

	/**
	 * Filter Event Dates
	 * @param  array $data  Array of Events
	 * @param  object $start  Carbon Object
	 * @param  object $stop   Carbon Object
	 * @return array  an array of events
	 */
	public function eventFilterDates($data, $start, $end, $timezone){
		$final = $data;
		foreach ($data as $key => $value) {
			if(!isset($value['starttime']) || !isset($value['endtime'])){
				unset($data[$key]);
				continue;
			}
			$tz = isset($value['timezone'])?$value['timezone']:$timezone;
			$startdate = Carbon::createFromTimeStamp($value['starttime'],$tz);
			$enddate = Carbon::createFromTimeStamp($value['endtime'],$tz);

			if($start->between($startdate,$enddate) || $end->between($startdate,$enddate)) {
				continue;
			}

			if($startdate->between($start,$end) || $enddate->between($start,$end)) {
				continue;
			}

			$daysLong = $startdate->diffInDays($enddate);
			if($daysLong > 0) {
				$daterange = new \DatePeriod($startdate, CarbonInterval::day(), $enddate);
				foreach($daterange as $d) {
					if($d->between($start,$end)) {
						continue(2);
					}
				}
			}
			unset($final[$key]);
		}
		return $final;
	}

	/**
	 * Add Event to specific calendar
	 * @param string $calendarID  The Calendar ID
	 * @param string $eventID     The Event ID, if null will auto generatefc
	 * @param string $name        The event name
	 * @param string $description The event description
	 * @param string $starttime   The event start timezone
	 * @param string $endtime     The event end time
	 * @param boolean $recurring  Is this a recurring event
	 * @param array $rrules       Recurring rules
	 * @param array $categories   The categories assigned to this event
	 */
	public function addEvent($calendarID,$eventID=null,$name,$description,$starttime,$endtime,$timezone=null,$recurring=false,$rrules=array(),$categories=array()){
		$eventID = !is_null($eventID) ? $eventID : Uuid::uuid4();
		$this->updateEvent($calendarID,$eventID,$name,$description,$starttime,$endtime,$timezone,$recurring,$rrules,$categories);
	}

	/**
	 * Update Event on specific calendar
	 * @param string $calendarID  The Calendar ID
	 * @param string $eventID     The Event ID, if null will auto generatefc
	 * @param string $name        The event name
	 * @param string $description The event description
	 * @param string $starttime   The event start timezone
	 * @param string $endtime     The event end time
	 * @param boolean $recurring  Is this a recurring event
	 * @param array $rrules       Recurring rules
	 * @param array $categories   The categories assigned to this event
	 */
	public function updateEvent($calendarID,$eventID,$name,$description,$starttime,$endtime,$timezone=null,$recurring=false,$rrules=array(),$categories=array()) {
		if(!isset($eventID) || is_null($eventID) || trim($eventID) == "") {
			throw new \Exception("Event ID can not be blank");
		}
		$event = array(
			"name" => $name,
			"description" => $description,
			"recurring" => $recurring,
			"rrules" => $rrules,
			"events" => array(),
			"categories" => $categories,
			"timezone" => $timezone
		);
		if($recurring) {
			$oldEvent = $this->getConfig($eventID,$calendarID."-events");
			if(!empty($oldEvent)) {
				$event['events'] = $oldEvent['events'];
			}
			$event['events'][] = array(
				"starttime" => $starttime,
				"endtime" => $endtime
			);
		} else {
			$event['starttime'] = $starttime;
			$event['endtime'] = $endtime;
		}

		$this->setConfig($eventID,$event,$calendarID."-events");

		foreach($categories as $category) {
			$events = $this->getConfig($category,$calendarID."-categories-events");
			if(empty($events)) {
				$events = array(
					$eventID
				);
			} elseif(!in_array($eventID,$events)) {
				$events[] = $eventID;
			}
			$this->setConfig($category,$events,$calendarID."-categories-events");
		}
	}

	/**
	 * Delete event from specific calendar
	 * @param  string $calendarID The Calendar ID
	 * @param  string $eventID    The event ID
	 */
	public function deleteEvent($calendarID,$eventID) {
		$this->setConfig($eventID,false,$calendarID."-events");
	}

	public function addRemoteCalDavCalendar($name,$description,$purl,$surl,$username,$password,$calendars) {
		$uuid = Uuid::uuid4();
		$this->updateRemoteCalDavCalendar($uuid,$name,$description,$purl,$surl,$username,$password,$calendars);
	}

	public function updateRemoteCalDavCalendar($id,$name,$description,$purl,$surl,$username,$password,$calendars) {
		if(empty($id)) {
			throw new \Exception("Calendar ID is empty");
		}
		$calendar = array(
			"name" => $name,
			"description" => $description,
			"type" => "caldav",
			"purl" => $purl,
			"surl" => $surl,
			"username" => $username,
			"password" => $password,
			"calendars" => $calendars
		);
		$this->setConfig($id,$calendar,'calendars');
		$calendar['id'] = $id;
		$this->processCalendar($calendar);
	}

	/**
	 * Add a Remote Calendar
	 * @param string $name        The Calendar name
	 * @param string $description The Calendar description
	 * @param string $type        The Calendar type
	 * @param string $url         The Calendar URL
	 */
	public function addRemoteiCalCalendar($name,$description,$url) {
		$uuid = Uuid::uuid4();
		$this->updateRemoteiCalCalendar($uuid,$name,$description,$url);
	}

	/**
	 * Add Local Calendar
	 * @param string $name        The Calendar name
	 * @param string $description The Calendar description
	 * @param string $timezone    The Calendar timezone
	 */
	public function addLocalCalendar($name,$description,$timezone) {
		$uuid = Uuid::uuid4();
		$this->updateLocalCalendar($uuid,$name,$description,$timezone);
	}

	/**
	 * Sync Calendars
	 */
	public function sync() {
		$calendars = $this->listCalendars();
		foreach($calendars as $id => $calendar) {
			if($calendar['type'] !== "local") {
				$calendar['id'] = $id;
				$this->processCalendar($calendar);
			}
		}
	}

	/**
	 * Update a Remote Calendar's settings
	 * @param string $id          The Calendar ID
	 * @param string $name        The Calendar name
	 * @param string $description The Calendar description
	 * @param string $type        The Calendar type
	 * @param string $url         The Calendar URL
	 */
	public function updateRemoteiCalCalendar($id,$name,$description,$url) {
		if(empty($id)) {
			throw new \Exception("Calendar ID is empty");
		}
		$calendar = array(
			"name" => $name,
			"description" => $description,
			"type" => "ical",
			"url" => $url
		);
		$this->setConfig($id,$calendar,'calendars');
		$calendar['id'] = $id;
		$this->processCalendar($calendar);
	}

	/**
	 * Update a Remote Calendar's settings
	 * @param string $id          The Calendar ID
	 * @param string $name        The Calendar name
	 * @param string $description The Calendar description
	 * @param string $timezone    The Calendar
	 */
	public function updateLocalCalendar($id,$name,$description,$timezone) {
		if(empty($id)) {
			throw new \Exception("Calendar ID is empty");
		}
		$calendar = array(
			"name" => $name,
			"description" => $description,
			"type" => 'local',
			"timezone" => $timezone
		);
		$this->setConfig($id,$calendar,'calendars');
		$calendar['id'] = $id;
	}

	/**
	 * Process remote calendar actions
	 * @param  array $calendar Calendar information (From getCalendarByID)
	 */
	public function processCalendar($calendar) {
		if(empty($calendar['id'])) {
			throw new \Exception("Calendar ID can not be empty!");
		}

		switch($calendar['type']) {
			case "caldav":
				$caldavClient = new SimpleCalDAVClient();
				$caldavClient->connect($calendar['purl'], $calendar['username'], $calendar['password']);
				$cals = $caldavClient->findCalendars();
				foreach($calendar['calendars'] as $c) {
					if(isset($cals[$c])) {
						$caldavClient->setCalendar($cals[$c]);
						$events = $caldavClient->getEvents();
						foreach($events as $event) {
							$ical = $event->getData();
							$cal = new IcalParser();
							$cal->parseString($ical);
							$this->processiCalEvents($calendar['id'], $cal); //will ids clash? they shouldnt????
						}
					}
				}
			break;
			case "ical":
				$cal = new IcalParser();
				$cal->parseFile($calendar['url']);
				$this->processiCalEvents($calendar['id'], $cal);
			break;
			case "google":
				//https://developers.google.com/api-client-library/php/auth/web-app
				$client = getGoogleClient();
				$service = new \Google_Service_Calendar($client);
				// Print the next 10 events on the user's calendar.
				$calendarId = 'primary';
				$optParams = array(
				  'maxResults' => 10,
				  'orderBy' => 'startTime',
				  'singleEvents' => TRUE,
				  'timeMin' => date('c'),
				);
				$results = $service->events->listEvents($calendarId, $optParams);

				if (count($results->getItems()) == 0) {
				  print "No upcoming events found.\n";
				} else {
				  print "Upcoming events:\n";
				  foreach ($results->getItems() as $event) {
				    $start = $event->start->dateTime;
				    if (empty($start)) {
				      $start = $event->start->date;
				    }
				    printf("%s (%s)\n", $event->getSummary(), $start);
				  }
				}
			break;
		}
	}


	private function getGoogleClient() {
	  $client = new \Google_Client();
	  $client->setApplicationName('Google Calendar API PHP Quickstart');
	  $client->setScopes(implode(' ', array(
			Google_Service_Calendar::CALENDAR_READONLY)
		));
		$client->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . '/oauth2callback.php');
	  $client->setAuthConfigFile(__DIR__ . '/client_secret.json');
	  $client->setAccessType('offline');

		$auth_url = $client->createAuthUrl();

		//header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));

	  // Load previously authorized credentials from a file.
	  $credentialsPath = expandHomeDirectory('~/.credentials/calendar-php-quickstart.json');
	  if (file_exists($credentialsPath)) {
	    $accessToken = file_get_contents($credentialsPath);
	  } else {
	    // Request authorization from the user.
	    $authUrl = $client->createAuthUrl();
	    printf("Open the following link in your browser:\n%s\n", $authUrl);
	    print 'Enter verification code: ';
	    $authCode = trim(fgets(STDIN));

	    // Exchange authorization code for an access token.
	    $accessToken = $client->authenticate($authCode);

	    // Store the credentials to disk.
	    if(!file_exists(dirname($credentialsPath))) {
	      mkdir(dirname($credentialsPath), 0700, true);
	    }
	    file_put_contents($credentialsPath, $accessToken);
	    printf("Credentials saved to %s\n", $credentialsPath);
	  }
	  $client->setAccessToken($accessToken);

	  // Refresh the token if it's expired.
	  if ($client->isAccessTokenExpired()) {
	    $client->refreshToken($client->getRefreshToken());
	    file_put_contents($credentialsPath, $client->getAccessToken());
	  }
	  return $client;
	}

	/**
	 * Process iCal Type events
	 * @param  string     $calendarID The Calendar ID
	 * @param  IcalParser $cal        IcalParser Object reference of events
	 */
	private function processiCalEvents($calendarID, IcalParser $cal) {
		//dont let sql update until the end of this
		//This might be bad.. ok it probably is bad. We should just get a Range of events
		//works for now though.
		$this->db->beginTransaction();

		//Trash old events because tracking by UIDs for Google is a whack-attack
		//The UIDs for matching elements should still match unless the calendar
		//has drastically changed and I couldn't track them even if I wanted to!!
		$this->delById($calendarID."-events");
		$this->delById($calendarID."-linked-events");
		$this->delById($calendarID."-categories-events");

		foreach ($cal->getSortedEvents() as $event) {
			if($event['DTSTART']->format('U') == 0) {
				continue;
			}

			$event['UID'] = isset($event['UID']) ? $event['UID'] : 0;

			$this->processiCalEvent($calendarID, $event);
		}

		$this->db->commit(); //now update just incase this takes a long time
	}

	/**
	 * Process single iCalEvent
	 * @param  string $calendarID The Calendar ID
	 * @param  array $event      The iCal Event
	 */
	public function processiCalEvent($calendarID, $event) {
		$event['UID'] = isset($event['UID']) ? $event['UID'] : 0;

		if(!empty($event['RECURRING'])) {
			$recurring = true;
			$rrules = array(
				"frequency" => $event['RRULE']['FREQ'],
				"days" => !empty($event['RRULE']['BYDAY']) ? explode(",",$event['RRULE']['BYDAY']) : array(),
				"byday" => !empty($event['RRULE']['BYDAY']) ? $event['RRULE']['BYDAY'] : array(),
				"interval" => !empty($event['RRULE']['INTERVAL']) ? $event['RRULE']['INTERVAL'] : "",
				"count" => !empty($event['RRULE']['COUNT']) ? $event['RRULE']['COUNT'] : "",
				"until" => !empty($event['RRULE']['UNTIL']) ? $event['RRULE']['UNTIL']->format('U') : ""
			);
		} else {
			$recurring = false;
			$rrules = array();
		}

		$categories = (!empty($event['CATEGORIES']) && is_array($event['CATEGORIES'])) ? $event['CATEGORIES'] : array();

		$event['DESCRIPTION'] = !empty($event['DESCRIPTION']) ? $event['DESCRIPTION'] : "";

		if($event['DTSTART']->getTimezone() != $event['DTEND']->getTimezone()) {
			throw new \Exception("Start timezone and end timezone are different! Not sure what to do here");
		}
		$tz = $event['DTSTART']->getTimezone();
		$timezone = $tz->getName();
		$this->updateEvent($calendarID,$event['UID'],htmlspecialchars_decode($event['SUMMARY'], ENT_QUOTES),htmlspecialchars_decode($event['DESCRIPTION'], ENT_QUOTES),$event['DTSTART']->format('U'),$event['DTEND']->format('U'),$timezone,$recurring,$rrules,$categories);
	}

	/**
	 * Get all the Categories by Calendar ID
	 * @param  string $calendarID The Calendar ID
	 * @return array             Array of Categories with their respective events
	 */
	public function getCategoriesByCalendarID($calendarID) {
		$categories = $this->getAll($calendarID."-categories-events");
		return $categories;
	}

	/**
	 * Add Event Group
	 * @param string $description   The Event Group name
	 * @param array $events The event group events
	 */
	public function addGroup($name,$calendars,$categories,$events) {
		$uuid = Uuid::uuid4();
		$this->updateGroup($uuid,$name,$calendars,$categories,$events);
	}

	/**
	 * Update Event Group
	 * @param string $id The event group id
	 * @param string $description   The Event Group name
	 * @param array $events The event group events
	 */
	public function updateGroup($id,$name,$calendars,$categories,$events) {
		if(empty($id)) {
			throw new \Exception("Event ID can not be blank");
		}
		$event = array(
			"name" => $name,
			"calendars" => $calendars,
			"categories" => $categories,
			"events" => $events
		);
		$this->setConfig($id,$event,"groups");
	}

	/**
	 * Delete Event Group
	 * @param  string $id The event group id
	 */
	public function deleteGroup($id){
		$this->setConfig($id, false, 'groups');
	}

	/**
	 * Get an Event Group by ID
	 * @param  string $id The event group id
	 * @return array     Event Group array
	 */
	public function getGroup($id){
		$grp = $this->getConfig($id,'groups');
		$grp['id'] = $id;
		return $grp;
	}

	/**
	 * List all Event Groups
	 * @return array Even Groups
	 */
	public function listGroups(){
			return $this->getAll('groups');
	}

	/**
	 * Dial Plan Function
	 */
	public function ext_calendar_group_variable($groupid,$timezone=null,$integer=false) {
		$timezone = empty($timezone) ? $this->systemtz : $timezone;
		$group = $this->getGroup($groupid);
		if(empty($group)) {
			throw new \Exception("Group $groupid does not exist!");
		}
		$type = $integer ? 'integer' : 'boolean';
		return new \ext_agi('calendar.agi,group,'.$type.','.$groupid.','.$timezone);
	}

	/**
	 * Dial Plan Function
	 */
	public function ext_calendar_group_goto($groupid,$timezone=null,$true_dest,$false_dest) {
		$timezone = empty($timezone) ? $this->systemtz : $timezone;
		$group = $this->getGroup($groupid);
		if(empty($group)) {
			throw new \Exception("Group $groupid does not exist!");
		}
		return new \ext_agi('calendar.agi,group,goto,'.$groupid.','.$timezone.','.base64_encode($true_dest).','.base64_encode($false_dest));
	}

	/**
	 * Dial Plan Function
	 */
	public function ext_calendar_group_execif($groupid,$timezone=null,$true,$false) {
		$timezone = empty($timezone) ? $this->systemtz : $timezone;
		$group = $this->getGroup($groupid);
		if(empty($group)) {
			throw new \Exception("Group $groupid does not exist!");
		}
		return new \ext_agi('calendar.agi,group,execif,'.$groupid.','.$timezone.','.base64_encode($true).','.base64_encode($false));
	}

	public function matchCategory($calendarID,$category) {

	}

	/**
	 * Checks if any event in said calendar matches the current time
	 * @param  string $calendarID The Calendar ID
	 * @return boolean          True if match, False if no match
	 */
	public function matchCalendar($calendarID) {
		//move back 1 min and forward 1 min to extend our search
		//TODO: Check full hour?
		$start = $this->now->copy()->subMinute();
		$stop = $this->now->copy()->addMinute();
		$events = $this->listEvents($calendarID, $start, $stop);
		foreach($events as $event) {
			if($event['now']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks if a specific event in a calendar matches the current time
	 * @param  string $calendarID The Calendar ID
	 * @param  string $eventID    The Event ID
	 * @return boolean          True if match, False if no match
	 */
	public function matchEvent($calendarID,$eventID) {
		$event = $this->getEvent($calendarID,$eventID);
		$start = Carbon::createFromTimeStamp($event['starttime'],$this->systemtz);
		$end = Carbon::createFromTimeStamp($event['endtime'],$this->systemtz);
		return $this->now->between($start,$end);
	}

	/**
	 * Checks if the Group Matches the current time
	 * @param  string $groupID The Group ID
	 * @return boolean          True if match, False if no match
	 */
	public function matchGroup($groupID) {
		//move back 1 min and forward 1 min to extend our search
		//TODO: Check full hour?
		$start = $this->now->copy()->subMinute();
		$stop = $this->now->copy()->addMinute();
		//1 query for each calendar instead of 1 query for each event
		$calendars = $this->listCalendars();
		$group = $this->getGroup($groupID);
		if(empty($group)) {
			return false;
		}
		$events = array();
		foreach($calendars as $cid => $calendar) {
			$events = $this->listEvents($cid, $start, $stop);
			if(!empty($group['events'])) {
				foreach($group['events'] as $eventid) {
					$parts = explode("_",$eventid,2);
					$eid = $parts[1]; //eventid is second part, calendarid is first
					if(isset($events[$eid]) && $events[$eid]['now']) {
						return true;
					}
				}
			}
			if(!empty($data['categories'])) {
			}
			if(!empty($data['calendars'])) {
			}
		}
		return false;
	}

	public function getActionBar($request) {
		$buttons = array();
		switch($request['display']) {
			case 'calendar':
				$action = !empty($_GET['action']) ? $_GET['action'] : '';
				switch($action) {
					case "add":
						$buttons = array(
							'reset' => array(
								'name' => 'reset',
								'id' => 'reset',
								'value' => _('Reset')
							),
							'submit' => array(
								'name' => 'submit',
								'id' => 'submit',
								'value' => _('Submit')
							)
						);
					break;
					case "edit":
						$buttons = array(
							'delete' => array(
								'name' => 'delete',
								'id' => 'delete',
								'value' => _('Delete')
							),
							'reset' => array(
								'name' => 'reset',
								'id' => 'reset',
								'value' => _('Reset')
							),
							'submit' => array(
								'name' => 'submit',
								'id' => 'submit',
								'value' => _('Submit')
							)
						);
					break;
				}
			break;
			case 'calendargroups':
			$action = !empty($_GET['action']) ? $_GET['action'] : '';
			switch($action) {
				case "add":
					$buttons = array(
						'reset' => array(
							'name' => 'reset',
							'id' => 'reset',
							'value' => _('Reset')
						),
						'submit' => array(
							'name' => 'submit',
							'id' => 'submit',
							'value' => _('Submit')
						)
					);
				break;
				case "edit":
					$buttons = array(
						'delete' => array(
							'name' => 'delete',
							'id' => 'delete',
							'value' => _('Delete')
						),
						'reset' => array(
							'name' => 'reset',
							'id' => 'reset',
							'value' => _('Reset')
						),
						'submit' => array(
							'name' => 'submit',
							'id' => 'submit',
							'value' => _('Submit')
						)
					);
				break;
			}
			break;
		}
		return $buttons;
	}

	public function getRightNav($request) {
		$request['action'] = !empty($request['action']) ? $request['action'] : '';
		switch($request['action']) {
			case "add":
			case "edit":
			case "view":
				return load_view(__DIR__."/views/rnav.php",array());
			break;
		}
	}

	//UCP STUFF
	public function ucpConfigPage($mode, $user, $action) {
		if(empty($user)) {
			$enabled = ($mode == 'group') ? true : null;
		} else {
			if($mode == 'group') {
				$enabled = $this->FreePBX->Ucp->getSettingByGID($user['id'],'Calendar','enabled');
				$enabled = !($enabled) ? false : true;
			} else {
				$enabled = $this->FreePBX->Ucp->getSettingByID($user['id'],'Calendar','enabled');
			}
		}

		$html = array();
		$html[0] = array(
			"title" => _("Calendar"),
			"rawname" => "calendar",
			"content" => load_view(dirname(__FILE__)."/views/ucp_config.php",array("mode" => $mode, "enabled" => $enabled))
		);
		return $html;
	}
	public function ucpAddUser($id, $display, $ucpStatus, $data) {
		$this->ucpUpdateUser($id, $display, $ucpStatus, $data);
	}
	public function ucpUpdateUser($id, $display, $ucpStatus, $data) {
		if($display == 'userman' && isset($_POST['type']) && $_POST['type'] == 'user') {
			if(isset($_POST['calendar_enable']) && $_POST['calendar_enable'] == 'yes') {
				$this->FreePBX->Ucp->setSettingByID($id,'Calendar','enabled',true);
			}elseif(isset($_POST['calendar_enable']) && $_POST['calendar_enable'] == 'no') {
				$this->FreePBX->Ucp->setSettingByID($id,'Calendar','enabled',false);
			} elseif(isset($_POST['calendar_enable']) && $_POST['calendar_enable'] == 'inherit') {
				$this->FreePBX->Ucp->setSettingByID($id,'Calendar','enabled',null);
			}
		}
	}
	public function ucpDelUser($id, $display, $ucpStatus, $data) {}
	public function ucpAddGroup($id, $display, $data) {
		$this->ucpUpdateGroup($id,$display,$data);
	}
	public function ucpUpdateGroup($id,$display,$data) {
		if($display == 'userman' && isset($_POST['type']) && $_POST['type'] == 'group') {
			if(isset($_POST['calendar_enable']) && $_POST['calendar_enable'] == 'yes') {
				$this->FreePBX->Ucp->setSettingByGID($id,'Calendar','enabled',true);
			} else {
				$this->FreePBX->Ucp->setSettingByGID($id,'Calendar','enabled',false);
			}
		}
	}
	public function ucpDelGroup($id,$display,$data) {

	}
}
