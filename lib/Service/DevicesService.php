<?php

/**
 * Nextcloud - maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Maps\Service;

use OCP\IL10N;
use OCP\ILogger;
use OCP\DB\QueryBuilder\IQueryBuilder;

class DevicesService {

    private $l10n;
    private $logger;
    private $qb;

    public function __construct (ILogger $logger, IL10N $l10n) {
        $this->l10n = $l10n;
        $this->logger = $logger;
        $this->qb = \OC::$server->getDatabaseConnection()->getQueryBuilder();
    }

    /**
     * @param string $userId
     * @param int $pruneBefore
     * @return array with devices
     */
    public function getDevicesFromDB($userId) {
        $devices = [];
        $qb = $this->qb;
        $qb->select('id', 'user_agent', 'color')
            ->from('maps_devices', 'd')
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        $req = $qb->execute();

        while ($row = $req->fetch()) {
            array_push($devices, [
                'id' => intval($row['id']),
                'user_agent' => $row['user_agent'],
                'color' => $row['color']
            ]);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $devices;
    }

    public function getDevicePointsFromDB($userId, $deviceId, $pruneBefore=0) {
        $qb = $this->qb;
        // get coordinates
        $qb->select('p.id', 'lat', 'lng', 'timestamp', 'altitude', 'accuracy', 'battery')
            ->from('maps_device_points', 'p')
            ->innerJoin('p', 'maps_devices', 'd', $qb->expr()->eq('d.id', 'p.device_id'))
            ->where(
                $qb->expr()->eq('p.device_id', $qb->createNamedParameter($deviceId, IQueryBuilder::PARAM_INT))
            )
            ->andWhere(
                $qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        if (intval($pruneBefore) > 0) {
            $qb->andWhere(
                $qb->expr()->gt('timestamp', $qb->createNamedParameter($pruneBefore, IQueryBuilder::PARAM_INT))
            );
        }
        $qb->orderBy('timestamp', 'ASC');
        $req = $qb->execute();

        $points = [];
        while ($row = $req->fetch()) {
            array_push($points, [
                'id' => intval($row['id']),
                'lat' => floatval($row['lat']),
                'lng' => floatval($row['lng']),
                'timestamp' => intval($row['timestamp']),
                'altitude' => is_numeric($row['altitude']) ? floatval($row['altitude']) : null,
                'accuracy' => is_numeric($row['accuracy']) ? floatval($row['accuracy']) : null,
                'battery' => is_numeric($row['battery']) ? floatval($row['battery']) : null
            ]);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $points;
    }

    public function getOrCreateDeviceFromDB($userId, $userAgent) {
        $deviceId = null;
        $qb = $this->qb;
        $qb->select('id')
            ->from('maps_devices', 'd')
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('user_agent', $qb->createNamedParameter($userAgent, IQueryBuilder::PARAM_STR))
            );
        $req = $qb->execute();

        while ($row = $req->fetch()) {
            $deviceId = intval($row['id']);
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        if ($deviceId === null) {
            $qb->insert('maps_devices')
                ->values([
                    'user_agent' => $qb->createNamedParameter($userAgent, IQueryBuilder::PARAM_STR),
                    'user_id' => $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)
                ]);
            $req = $qb->execute();
            $deviceId = $qb->getLastInsertId();
            $qb = $qb->resetQueryParts();
        }
        return $deviceId;
    }

    public function addPointToDB($deviceId, $lat, $lng, $ts, $altitude, $battery, $accuracy) {
        $qb = $this->qb;
        $qb->insert('maps_device_points')
            ->values([
                'device_id' => $qb->createNamedParameter($deviceId, IQueryBuilder::PARAM_STR),
                'lat' => $qb->createNamedParameter($lat, IQueryBuilder::PARAM_STR),
                'lng' => $qb->createNamedParameter($lng, IQueryBuilder::PARAM_STR),
                'timestamp' => $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT),
                'altitude' => $qb->createNamedParameter($altitude, IQueryBuilder::PARAM_STR),
                'battery' => $qb->createNamedParameter($battery, IQueryBuilder::PARAM_STR),
                'accuracy' => $qb->createNamedParameter($accuracy, IQueryBuilder::PARAM_STR)
            ]);
        $req = $qb->execute();
        $pointId = $qb->getLastInsertId();
        $qb = $qb->resetQueryParts();
        return $pointId;
    }

    public function getDeviceFromDB($id, $userId) {
        $device = null;
        $qb = $this->qb;
        $qb->select('id', 'user_agent', 'color')
            ->from('maps_devices', 'd')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );
        if ($userId !== null) {
            $qb->andWhere(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        }
        $req = $qb->execute();

        while ($row = $req->fetch()) {
            $device = [
                'id' => intval($row['id']),
                'user_agent' => $row['user_agent'],
                'color' => $row['color']
            ];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $device;
    }

    public function editDeviceInDB($id, $color) {
        $qb = $this->qb;
        $qb->update('maps_devices');
        $qb->set('color', $qb->createNamedParameter($color, IQueryBuilder::PARAM_STR));
        $qb->where(
            $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
        );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();
    }

    public function deleteDeviceFromDB($id) {
        $qb = $this->qb;
        $qb->delete('maps_devices')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();

        $qb->delete('maps_device_points')
            ->where(
                $qb->expr()->eq('device_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();
    }

    public function countPoints($userId, $deviceIdList, $begin, $end) {
        $qb = $this->qb;
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('maps_devices', 'd')
            ->innerJoin('d', 'maps_device_points', 'p', $qb->expr()->eq('d.id', 'p.device_id'))
            ->where(
                $qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        if (is_array($deviceIdList) and count($deviceIdList) > 0) {
            $or = $qb->expr()->orx();
            foreach ($deviceIdList as $deviceId) {
                $or->add($qb->expr()->eq('d.id', $qb->createNamedParameter($deviceId, IQueryBuilder::PARAM_INT)));
            }
            $qb->andWhere($or);
        }
        else {
            return 0;
        }
        if ($begin !== null && is_numeric($begin)) {
            $qb->andWhere(
                $qb->expr()->gt('p.timestamp', $qb->createNamedParameter($begin, IQueryBuilder::PARAM_INT))
            );
        }
        if ($end !== null && is_numeric($end)) {
            $qb->andWhere(
                $qb->expr()->lt('p.timestamp', $qb->createNamedParameter($end, IQueryBuilder::PARAM_INT))
            );
        }
        $req = $qb->execute();
        $count = 0;
        while ($row = $req->fetch()) {
            $count = intval($row['COUNT(*)']);
            break;
        }
        $qb = $qb->resetQueryParts();

        return $count;
    }

    public function exportDevices($userId, $handler, $deviceIdList, $begin, $end, $appVersion, $filename) {
        $gpxHeader = $this->generateGpxHeader($filename, $appVersion, count($deviceIdList));
        fwrite($handler, $gpxHeader);

        foreach ($deviceIdList as $devid) {
            $nbPoints = $this->countPoints($userId, [$devid], $begin, $end);
            if ($nbPoints > 0) {
                $this->getAndWriteDevicePoints($devid, $begin, $end, $handler, $nbPoints, $userId);
            }
        }
        fwrite($handler, '</gpx>');
    }

    private function generateGpxHeader($name, $appVersion, $nbdev=0) {
        date_default_timezone_set('UTC');
        $dt = new \DateTime();
        $date = $dt->format('Y-m-d\TH:i:s\Z');
        $gpxText = '<?xml version="1.0" encoding="UTF-8" standalone="no" ?>' . "\n";
        $gpxText .= '<gpx xmlns="http://www.topografix.com/GPX/1/1"' .
            ' xmlns:gpxx="http://www.garmin.com/xmlschemas/GpxExtensions/v3"' .
            ' xmlns:wptx1="http://www.garmin.com/xmlschemas/WaypointExtension/v1"' .
            ' xmlns:gpxtpx="http://www.garmin.com/xmlschemas/TrackPointExtension/v1"' .
            ' creator="Nextcloud Maps v' .
            $appVersion. '" version="1.1"' .
            ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' .
            ' xsi:schemaLocation="http://www.topografix.com/GPX/1/1' .
            ' http://www.topografix.com/GPX/1/1/gpx.xsd' .
            ' http://www.garmin.com/xmlschemas/GpxExtensions/v3' .
            ' http://www8.garmin.com/xmlschemas/GpxExtensionsv3.xsd' .
            ' http://www.garmin.com/xmlschemas/WaypointExtension/v1' .
            ' http://www8.garmin.com/xmlschemas/WaypointExtensionv1.xsd' .
            ' http://www.garmin.com/xmlschemas/TrackPointExtension/v1' .
            ' http://www.garmin.com/xmlschemas/TrackPointExtensionv1.xsd">' . "\n";
        $gpxText .= '<metadata>' . "\n" . ' <time>' . $date . '</time>' . "\n";
        $gpxText .= ' <name>' . $name . '</name>' . "\n";
        if ($nbdev > 0) {
            $gpxText .= ' <desc>' . $nbdev . ' device'.($nbdev > 1 ? 's' : '').'</desc>' . "\n";
        }
        $gpxText .= '</metadata>' . "\n";
        return $gpxText;
    }

    private function getAndWriteDevicePoints($devid, $begin, $end, $fd, $nbPoints, $userId) {
        $device = $this->getDeviceFromDB($devid, $userId);
        $devname = $device['user_agent'];
        $qb = $this->qb;

        $gpxText  = '<trk>' . "\n" . ' <name>' . $devname . '</name>' . "\n";
        $gpxText .= ' <trkseg>' . "\n";
        fwrite($fd, $gpxText);

        $chunkSize = 10000;
        $pointIndex = 0;

        while ($pointIndex < $nbPoints) {
            $gpxText = '';
            $qb->select('id', 'lat', 'lng', 'timestamp', 'altitude', 'accuracy', 'battery')
                ->from('maps_device_points', 'p')
                ->where(
                    $qb->expr()->eq('device_id', $qb->createNamedParameter($devid, IQueryBuilder::PARAM_INT))
                );
            if (intval($begin) > 0) {
                $qb->andWhere(
                    $qb->expr()->gt('timestamp', $qb->createNamedParameter($begin, IQueryBuilder::PARAM_INT))
                );
            }
            if (intval($end) > 0) {
                $qb->andWhere(
                    $qb->expr()->lt('timestamp', $qb->createNamedParameter($end, IQueryBuilder::PARAM_INT))
                );
            }
            $qb->setFirstResult($pointIndex);
            $qb->setMaxResults($chunkSize);
            $qb->orderBy('timestamp', 'ASC');
            $req = $qb->execute();

            while ($row = $req->fetch()) {
                $id = intval($row['id']);
                $lat = floatval($row['lat']);
                $lng = floatval($row['lng']);
                $epoch = $row['timestamp'];
                $date = '';
                if (is_numeric($epoch)) {
                    $epoch = intval($epoch);
                    $dt = new \DateTime("@$epoch");
                    $date = $dt->format('Y-m-d\TH:i:s\Z');
                }
                $alt = $row['altitude'];
                $acc = $row['accuracy'];
                $bat = $row['battery'];

                $gpxExtension = '';
                $gpxText .= '  <trkpt lat="'.$lat.'" lon="'.$lng.'">' . "\n";
                $gpxText .= '   <time>' . $date . '</time>' . "\n";
                if (is_numeric($alt)) {
                    $gpxText .= '   <ele>' . sprintf('%.2f', floatval($alt)) . '</ele>' . "\n";
                }
                if (is_numeric($acc) && intval($acc) >= 0) {
                    $gpxExtension .= '     <accuracy>' . sprintf('%.2f', floatval($acc)) . '</accuracy>' . "\n";
                }
                if (is_numeric($bat) && intval($bat) >= 0) {
                    $gpxExtension .= '     <batterylevel>' . sprintf('%.2f', floatval($bat)) . '</batterylevel>' . "\n";
                }
                if ($gpxExtension !== '') {
                    $gpxText .= '   <extensions>'. "\n" . $gpxExtension;
                    $gpxText .= '   </extensions>' . "\n";
                }
                $gpxText .= '  </trkpt>' . "\n";
            }
            $req->closeCursor();
            $qb = $qb->resetQueryParts();

            // write the chunk
            fwrite($fd, $gpxText);
            $pointIndex = $pointIndex + $chunkSize;
        }
        $gpxText  = ' </trkseg>' . "\n";
        $gpxText .= '</trk>' . "\n";
        fwrite($fd, $gpxText);
    }

}
