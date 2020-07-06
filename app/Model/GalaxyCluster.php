<?php
App::uses('AppModel', 'Model');
class GalaxyCluster extends AppModel
{
    public $useTable = 'galaxy_clusters';

    public $recursive = -1;

    public $actsAs = array(
            'Containable',
    );

    private $__assetCache = array();

    public $validate = array(
        'name' => array(
            'stringNotEmpty' => array(
                'rule' => array('stringNotEmpty')
            )
        ),
        'description' => array(
            'stringNotEmpty' => array(
                'rule' => array('stringNotEmpty')
            )
        ),
        'uuid' => array(
            'uuid' => array(
                'rule' => array('custom', '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/'),
                'message' => 'Please provide a valid UUID'
            ),
            'unique' => array(
                'rule' => 'isUnique',
                'message' => 'The UUID provided is not unique',
                'required' => 'create'
            )
        ),
        'distribution' => array(
            'rule' => array('inList', array('0', '1', '2', '3', '4')),
            'message' => 'Options: Your organisation only, This community only, Connected communities, All communities, Sharing group',
            'required' => true
        ),
        'published' => array(
            'boolean' => array(
                'rule' => array('boolean'),
            ),
        ),
    );

    public $belongsTo = array(
        'Galaxy' => array(
            'className' => 'Galaxy',
            'foreignKey' => 'galaxy_id',
        ),
        'Tag' => array(
            'foreignKey' => false,
            'conditions' => array('GalaxyCluster.tag_name = Tag.name')
        ),
        'Org' => array(
            'className' => 'Organisation',
            'foreignKey' => 'org_id'
        ),
        'Orgc' => array(
            'className' => 'Organisation',
            'foreignKey' => 'orgc_id'
        ),
        'SharingGroup' => array(
                'className' => 'SharingGroup',
                'foreignKey' => 'sharing_group_id'
        )
    );

    private $__clusterCache = array();
    private $deleteClusterUUID;

    public $hasMany = array(
        'GalaxyElement' => array('dependent' => true),
        'GalaxyClusterRelation' => array(
            'className' => 'GalaxyClusterRelation',
            'foreignKey' => 'galaxy_cluster_id',
            'dependent' => true,
        ),
        'TargetingClusterRelation' => array(
            'className' => 'GalaxyClusterRelation',
            'foreignKey' => 'referenced_galaxy_cluster_id',
        ),
    );

    public $validFormats = array(
        'json' => array('json', 'JsonExport', 'json'),
    );

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        if (!isset($this->data['GalaxyCluster']['description'])) {
            $this->data['GalaxyCluster']['description'] = '';
        }
        if ($this->data['GalaxyCluster']['distribution'] != 4) {
            $this->data['GalaxyCluster']['sharing_group_id'] = null;
        }
        if (!isset($this->data['GalaxyCluster']['published'])) {
            $this->data['GalaxyCluster']['published'] = false;
        }
        if (!isset($this->data['GalaxyCluster']['authors']) || is_null($this->data['GalaxyCluster']['authors'])) {
            $this->data['GalaxyCluster']['authors'] = '';
        } elseif (is_array($this->data['GalaxyCluster']['authors'])) {
            $this->data['GalaxyCluster']['authors'] = json_encode($this->data['GalaxyCluster']['authors']);
        }
        return true;
    }

    public function afterFind($results, $primary = false)
    {
        foreach ($results as $k => $result) {
            if (isset($results[$k][$this->alias]['authors'])) {
                $results[$k][$this->alias]['authors'] = json_decode($results[$k][$this->alias]['authors'], true);
            }
            if (isset($results[$k][$this->alias]['distribution']) && $results[$k][$this->alias]['distribution'] != 4) {
                unset($results[$k]['SharingGroup']);
            }
            if (isset($results[$k][$this->alias]['org_id']) && $results[$k][$this->alias]['org_id'] == 0) {
                if (isset($results[$k]['Org'])) {
                    $results[$k]['Org'] = $this->Org->genericMISPOrganisation;
                }
            }
            if (isset($results[$k][$this->alias]['orgc_id']) && $results[$k][$this->alias]['orgc_id'] == 0) {
                if (isset($results[$k]['Orgc'])) {
                    $results[$k]['Orgc'] = $this->Org->genericMISPOrganisation;
                }
            }

            if (!empty($results[$k]['GalaxyClusterRelation'])) {
                foreach ($results[$k]['GalaxyClusterRelation'] as $i => $relation) {
                    if (isset($relation['distribution']) && $relation['distribution'] != 4) {
                        unset($results[$k]['GalaxyClusterRelation'][$i]['SharingGroup']);
                    }
                }
            }
        }
        return $results;
    }

    public function afterSave($created, $options = array())
    {
        // Update all relations IDs that are unkown but saved (UUID is set)
        parent::afterSave($created, $options);
        $cluster = $this->data[$this->alias];
        $cluster = $this->fetchAndSetUUID($cluster);
        $this->GalaxyClusterRelation->updateAll(
            array('GalaxyClusterRelation.referenced_galaxy_cluster_id' => $cluster['id']),
            array('GalaxyClusterRelation.referenced_galaxy_cluster_uuid' => $cluster['uuid'])
        );
    }

    public function afterDelete()
    {
        // Remove all relations IDs now that the cluster is unkown
        if (!empty($this->deletedClusterUUID)) {
            $this->GalaxyClusterRelation->updateAll(
                array('GalaxyClusterRelation.referenced_galaxy_cluster_id' => 0),
                array('GalaxyClusterRelation.referenced_galaxy_cluster_uuid' => $this->deletedClusterUUID)
            );
            $this->GalaxyElement->deleteAll(array('GalaxyElement.galaxy_cluster_id' => $this->id));
            $this->GalaxyClusterRelation->deleteAll(array('GalaxyClusterRelation.galaxy_cluster_uuid' => $this->deletedClusterUUID));
        }
    }

    public function beforeDelete($cascade = true)
    {
        $cluster = $this->find('first', array(
            'conditions' => array('id' => $this->id),
            'fields' => array('uuid'),
        ));
        if (!empty($cluster)) {
            $this->deletedClusterUUID = $cluster[$this->alias]['uuid'];
        } else {
            $this->deletedClusterUUID = null;
        }
    }

    public function arrangeData($cluster)
    {
        $models = array('Galaxy', 'SharingGroup', 'GalaxyElement', 'GalaxyClusterRelation', 'Org', 'Orgc', 'TargetingClusterRelation');
        foreach ($models as $model) {
            if (isset($cluster[$model])) {
                $cluster['GalaxyCluster'][$model] = $cluster[$model];
                unset($cluster[$model]);
            }
        }
        return $cluster;
    }

    public function fetchAndSetUUID($cluster)
    {
        if (!isset($cluster['uuid'])) {
            $alias = $this->alias;
            $tmp = $this->find('first', array(
                'recursive' => -1,
                'fields' => array("${alias}.id", "${alias}.uuid"),
                'conditions' => array("${alias}.id" => $cluster['id'])
            ));
            $cluster['uuid'] = $tmp[$alias]['uuid'];
        }
        return $cluster;
    }

    // Respecting ACL, save a cluster, its elements and set correct fields
    public function saveCluster($user, $cluster, $allowEdit=false)
    {
        $errors = array();
        if (!$user['Role']['perm_galaxy_editor'] && !$user['Role']['perm_site_admin']) {
            $errors[] = __('Incorrect permission');
            return $errors;
        }
        $galaxy = $this->Galaxy->find('first', array('conditions' => array(
            'id' => $cluster['GalaxyCluster']['galaxy_id']
        )));
        if (empty($galaxy)) {
            $errors[] = __('Galaxy not found');
            return $errors;
        } else {
            $galaxy = $galaxy['Galaxy'];
        }
        unset($cluster['GalaxyCluster']['id']);
        $cluster['GalaxyCluster']['locked'] = false;
        if (isset($cluster['GalaxyCluster']['uuid'])) {
            // check if the uuid already exists
            $existingGalaxyCluster = $this->find('first', array('conditions' => array('GalaxyCluster.uuid' => $cluster['GalaxyCluster']['uuid'])));
            if ($existingGalaxyCluster) {
                if ($existingGalaxyCluster['GalaxyCluster']['galaxy_id'] != $galaxy['id']) { // cluster already exists in another galaxy
                    $errors[] = __('Cluster already exists in another galaxy');
                return $errors;
                }
                if (!$existingGalaxyCluster['GalaxyCluster']['default']) {
                    $errors[] = __('Edit not allowed on default clusters');
                    return $errors;
                }
                if (!$allowEdit) {
                    $errors[] = __('Edit not allowed');
                    return $errors;
                }
                $errors = $this->editCluster($user, $cluster);
                return $errors;
            }
        } else {
            $cluster['GalaxyCluster']['uuid'] = CakeText::uuid();
        }
        if (!isset($cluster['GalaxyCluster']['published'])) {
            $cluster['GalaxyCluster']['published'] = false;
        }
        if (!isset($cluster['GalaxyCluster']['collection_uuid'])) {
            $cluster['GalaxyCluster']['collection_uuid'] = '';
        }
        if (!empty($cluster['GalaxyCluster']['extends_uuid'])) {
            
            $forkedCluster = $this->find('first', array('conditions' => array('GalaxyCluster.uuid' => $cluster['GalaxyCluster']['extends_uuid'])));
            if (!empty($forkedCluster) && $forkedCluster['GalaxyCluster']['galaxy_id'] != $galaxy['id']) {
                $errors[] = __('Cluster forks always have to belong to the same galaxy as the parent');
                return $errors;
            }
        } else {
            $cluster['GalaxyCluster']['extends_version'] = null;
        }
        $cluster['GalaxyCluster']['org_id'] = $user['Organisation']['id'];
        $cluster['GalaxyCluster']['orgc_id'] = $user['Organisation']['id'];

        if ($user['Role']['perm_sync']) {
            if (isset($cluster['GalaxyCluster']['distribution']) && $cluster['GalaxyCluster']['distribution'] == 4 && !$this->SharingGroup->checkIfAuthorised($user, $cluster['GalaxyCluster']['sharing_group_id'])) {
                $errors[] = __('The sync user has to have access to the sharing group in order to be able to edit it');
            return $errors;
            }
        }

        $cluster['GalaxyCluster']['type'] = $galaxy['type'];
        if (!isset($cluster['GalaxyCluster']['version'])) {
            $date = new DateTime();
            $cluster['GalaxyCluster']['version'] = $date->getTimestamp();
        }
        $cluster['GalaxyCluster']['tag_name'] = sprintf('misp-galaxy:%s="%s"', $galaxy['type'], $cluster['GalaxyCluster']['uuid']);
        $this->create();
        $saveSuccess = $this->save($cluster);
        if ($saveSuccess) {
            $savedCluster = $this->find('first', array(
                'conditions' => array('id' =>  $this->id),
                'recursive' => -1
            ));

            if (!empty($cluster['GalaxyCluster']['GalaxyElement'])) {
                $elementsToSave = array();
                foreach ($cluster['GalaxyCluster']['GalaxyElement'] as $element) { // transform cluster into Galaxy meta format
                    $elementsToSave[$element['key']][] = $element['value'];
                }
                $this->GalaxyElement->updateElements(-1, $savedCluster['GalaxyCluster']['id'], $elementsToSave);
            }
            if (!empty($cluster['GalaxyCluster']['GalaxyClusterRelation'])) {
                $this->GalaxyClusterRelation->saveRelations($user, $cluster['GalaxyCluster'], $cluster['GalaxyCluster']['GalaxyClusterRelation'], $capture=true);
            }
        } else {
            foreach($this->validationErrors as $validationError) {
                $errors[] = $validationError[0];
            }
        }
        return $errors;
    }

    public function editCluster($user, $cluster, $fieldList = array(), $deleteOldElements=true)
    {
        $this->SharingGroup = ClassRegistry::init('SharingGroup');
        $errors = array();
        if (!$user['Role']['perm_galaxy_editor'] && !$user['Role']['perm_site_admin']) {
            $errors[] = __('Incorrect permission');
        }
        if (isset($cluster['GalaxyCluster']['uuid'])) {
            $existingCluster = $this->find('first', array('conditions' => array('GalaxyCluster.uuid' => $cluster['GalaxyCluster']['uuid'])));
        } else {
            $errors[] = __('UUID not provided');
        }
        if (empty($existingCluster)) {
            $errors[] = __('Unkown UUID');
        } else {
            // For users that are of the creating org of the cluster, always allow the edit
            // For users that are sync users, only allow the edit if the cluster is locked
            if ($existingCluster['GalaxyCluster']['orgc_id'] === $user['org_id']
            || ($user['Role']['perm_sync'] && $existingCluster['GalaxyCluster']['locked']) || $user['Role']['perm_site_admin']) {
                if ($user['Role']['perm_sync']) {
                    if (isset($cluster['GalaxyCluster']['distribution']) && $cluster['GalaxyCluster']['distribution'] == 4 && !$this->SharingGroup->checkIfAuthorised($user, $cluster['GalaxyCluster']['sharing_group_id'])) {
                        $errors[] = array(__('Galaxy Cluster could not be saved: The sync user has to have access to the sharing group in order to be able to edit it.'));
                    }
                }
            } else {
                $errors[] = array(__('Galaxy Cluster could not be saved: The user used to edit the cluster is not authorised to do so. This can be caused by the user not being of the same organisation as the original creator of the cluster whilst also not being a site administrator.'));
            }
            $cluster['GalaxyCluster']['id'] = $existingCluster['GalaxyCluster']['id'];

            if (empty($errors)) {
                $date = new DateTime();
                $cluster['GalaxyCluster']['version'] = $date->getTimestamp();
                $cluster['GalaxyCluster']['default'] = false;
                if (!isset($cluster['GalaxyCluster']['published'])) {
                    $cluster['GalaxyCluster']['published'] = false;
                }
                if (empty($fieldList)) {
                    $fieldList = array('value', 'description', 'version', 'source', 'authors', 'distribution', 'sharing_group_id', 'default', 'published');
                }
                $saveSuccess = $this->save($cluster, array('fieldList' => $fieldList));
                if ($saveSuccess) {
                    if (!empty($cluster['GalaxyCluster']['GalaxyElement'])) {
                        $elementsToSave = array();
                        foreach ($cluster['GalaxyCluster']['GalaxyElement'] as $element) { // transform cluster into Galaxy meta format
                            $elementsToSave[$element['key']][] = $element['value'];
                        }
                        $this->GalaxyElement->updateElements($cluster['GalaxyCluster']['id'], $cluster['GalaxyCluster']['id'], $elementsToSave, $delete=$deleteOldElements);
                    }
                    if (!empty($cluster['GalaxyCluster']['GalaxyClusterRelation'])) {
                        $this->GalaxyClusterRelation->saveRelations($user, $cluster['GalaxyCluster'], $cluster['GalaxyCluster']['GalaxyClusterRelation'], $capture=true, $force=true);
                    }

                } else {
                    foreach($this->validationErrors as $validationError) {
                        $errors[] = $validationError[0];
                    }
                }
            }
        }
        return $errors;
    }

    public function publishRouter($user, $cluster, $passAlong=null)
    {
        if (Configure::read('MISP.background_jobs')) {
            if (is_numeric($cluster)) {
                $clusterId = $cluster;
            } elseif (isset($cluster['GalaxyCluster'])) {
                $clusterId = $cluster['GalaxyCluster']['id'];
            } else {
                return false;
            }
            $this->Event = ClassRegistry::init('Event');
            $job_type = 'publish_cluster';
            $function = 'publish_galaxy_clusters';
            $message = 'Publishing.';
            $job = ClassRegistry::init('Job');
            $job->create();
            $data = array(
                    'worker' => $this->Event->__getPrioWorkerIfPossible(),
                    'job_type' => 'publish_galaxy_clusters',
                    'job_input' => 'Cluster ID: ' . $clusterId,
                    'status' => 0,
                    'retries' => 0,
                    'org_id' => $user['org_id'],
                    'org' => $user['Organisation']['name'],
                    'message' => $message
            );
            $job->save($data);
            $jobId = $job->id;
            $process_id = CakeResque::enqueue(
                    'prio',
                    'EventShell',
                    array($function, $clusterId, $jobId, $user['id'], $passAlong),
                    true
            );
            $job->saveField('process_id', $process_id);
            return $process_id;
        } else {
            $result = $this->publish($cluster, $passAlong=$passAlong);
            return $result;
        }
    }

    public function publish($cluster, $passAlong=null)
    {
        if (is_numeric($cluster)) {
            $clusterId = $cluster;
            $this->id = $cluster;
            $saved = $this->saveField('published', 1);
        } elseif (isset($cluster['GalaxyCluster'])) {
            $clusterId = $cluster['GalaxyCluster']['id'];
            $cluster['GalaxyCluster']['published'] = true;
            $saved = $this->save($cluster, array('fieldList' => array('published')));
        }
        if ($saved) {
            $uploaded = $this->uploadClusterToServersRouter($clusterId);
            return $uploaded;
        }
        return false;
    }

    public function unpublish($cluster)
    {
        if (is_numeric($cluster)) {
            $this->id = $cluster;
            return $this->saveField('published', 0);
        } elseif (isset($cluster['GalaxyCluster'])) {
            $cluster['GalaxyCluster']['published'] = false;
            return $this->save($cluster, array('fieldList' => array('published')));
        }
        return false;
    }

    // Upload this specific cluster to all remote servers
    private function uploadClusterToServersRouter($clusterId, $passAlong=null)
    {
        $clusterOrgcId = $this->find('first', array(
            'conditions' => array('GalaxyCluster.id' => $clusterId),
            'recursive' => -1,
            'fields' => array('GalaxyCluster.orgc_id')
        ));
        $elevatedUser = array(
            'Role' => array(
                'perm_site_admin' => 1,
                'perm_sync' => 1
            ),
            'org_id' => $clusterOrgcId['GalaxyCluster']['orgc_id']
        );
        $elevatedUser['Role']['perm_site_admin'] = 1;
        $elevatedUser['Role']['perm_sync'] = 1;
        $elevatedUser['Role']['perm_audit'] = 0;
        $cluster = $this->fetchGalaxyClusters($elevatedUser, array('minimal' => true, 'conditions' => array('id' => $clusterId)), $full=false);
        if (empty($cluster)) {
            return true;
        }
        $cluster = $cluster[0];

        $this->Server = ClassRegistry::init('Server');
        $conditions = array('push' => 1, 'push_galaxy_clusters' => 1);
        if ($passAlong) {
            $conditions[] = array('Server.id !=' => $passAlong);
        }
        $servers = $this->Server->find('all', array(
            'conditions' => $conditions,
            'order' => array('Server.priority ASC', 'Server.id ASC')
        ));
        // iterate over the servers and upload the event
        if (empty($servers)) {
            return true;
        }
        $uploaded = false;
        $failedServers = array();
        App::uses('SyncTool', 'Tools');
        foreach ($servers as &$server) {
            if ((!isset($server['Server']['internal']) || !$server['Server']['internal']) && $cluster['GalaxyCluster']['distribution'] < 2) {
                continue;
            }
            $syncTool = new SyncTool();
            $HttpSocket = $syncTool->setupHttpSocket($server);
            $fakeSyncUser = array(
                'id' => 0,
                'email' => 'fakeSyncUser@user.test',
                'org_id' => $server['Server']['remote_org_id'],
                'Organisation' => array(
                    'id' => $server['Server']['remote_org_id'],
                    'name' => 'fakeSyncOrg',
                ),
                'Role' => array(
                    'perm_site_admin' => 0,
                    'perm_sync' => 1
                )
            );
            $cluster = $this->fetchGalaxyClusters($fakeSyncUser, array('conditions' => array('GalaxyCluster.id' => $clusterId)), $full=true);
            if (empty($cluster)) {
                return true;
            }
            $cluster = $cluster[0];
            $result = $this->uploadClusterToServer($cluster, $server, $HttpSocket, $fakeSyncUser);
            if ($result == 'Success') {
                $uploaded = true;
            } else {
                $failedServers[] = $server;
            }
        }
        return $uploaded;
    }

    public function unsetFieldsForExport($clusters)
    {
        foreach ($clusters as $k => $cluster) {
            unset($clusters[$k]['GalaxyCluster']['galaxy_id']);
            $modelsToUnset = array('GalaxyCluster', 'Galaxy', 'Org', 'Orgc');
            forEach($modelsToUnset as $modelName) {
                unset($clusters[$k][$modelName]['id']);
            }
            $modelsToUnset = array('GalaxyClusterRelation', 'TargetingClusterRelation');
            forEach($modelsToUnset as $modelName) {
                forEach($cluster['GalaxyCluster'][$modelName] as $i => $relation) {
                    unset($clusters[$k]['GalaxyCluster'][$modelName][$i]['id']);
                    unset($clusters[$k]['GalaxyCluster'][$modelName][$i]['galaxy_cluster_id']);
                    unset($clusters[$k]['GalaxyCluster'][$modelName][$i]['referenced_galaxy_cluster_id']);
                    if (isset($relation['Tag'])) {
                        forEach($relation['Tag'] as $j => $tags) {
                            unset($clusters[$k]['GalaxyCluster'][$modelName][$i]['Tag'][$j]['id']);
                            unset($clusters[$k]['GalaxyCluster'][$modelName][$i]['Tag'][$j]['org_id']);
                            unset($clusters[$k]['GalaxyCluster'][$modelName][$i]['Tag'][$j]['user_id']);
                        }
                    }
                }
            }
            forEach($cluster['GalaxyCluster']['GalaxyElement'] as $i => $element) {
                unset($clusters[$k]['GalaxyCluster']['GalaxyElement'][$i]['id']);
                unset($clusters[$k]['GalaxyCluster']['GalaxyElement'][$i]['galaxy_cluster_id']);
            }
        }
        return $clusters;
    }

    /**
     * Gets a cluster then save it.
     *
     * @param $user
     * @param array $cluster Cluster to be saved
     * @param bool $fromPull If the current capture is performed from a PULL sync
     * @param int $orgId The organisation id that should own the cluster
     * @param array $server The server for which to capture is ongoing
     * @return array
     */
    public function captureCluster($user, $cluster, $fromPull=false, $orgId=0, $server=false)
    {
        $results = array('success' => false, 'imported' => 0, 'ignored' => 0, 'failed' => 0, 'errors' => array());

        if ($fromPull) {
            $cluster['GalaxyCluster']['org_id'] = $orgId;
        } else {
            $cluster['GalaxyCluster']['org_id'] = $user['Organisation']['id'];
        }

        if (!isset($cluster['GalaxyCluster']['orgc_id']) && !isset($cluster['Orgc'])) {
            $cluster['GalaxyCluster']['orgc_id'] = $cluster['GalaxyCluster']['org_id'];
        } else {
            if (!isset($cluster['GalaxyCluster']['Orgc'])) {
                if (isset($cluster['GalaxyCluster']['orgc_id']) && $cluster['GalaxyCluster']['orgc_id'] != $user['org_id'] && !$user['Role']['perm_sync'] && !$user['Role']['perm_site_admin']) {
                    $cluster['GalaxyCluster']['orgc_id'] = $cluster['GalaxyCluster']['org_id']; // Only sync user can create cluster on behalf of other users
                }
            } else {
                if ($cluster['GalaxyCluster']['Orgc']['uuid'] != $user['Organisation']['uuid'] && !$user['Role']['perm_sync'] && !$user['Role']['perm_site_admin']) {
                    $cluster['GalaxyCluster']['orgc_id'] = $cluster['GalaxyCluster']['org_id']; // Only sync user can create cluster on behalf of other users
                }
            }
            if (isset($cluster['GalaxyCluster']['orgc_id']) && $cluster['GalaxyCluster']['orgc_id'] != $user['org_id'] && !$user['Role']['perm_sync'] && !$user['Role']['perm_site_admin']) {
                $cluster['GalaxyCluster']['orgc_id'] = $cluster['GalaxyCluster']['org_id']; // Only sync user can create cluster on behalf of other users
            }
        }

        if (!Configure::check('MISP.enableOrgBlacklisting') || Configure::read('MISP.enableOrgBlacklisting') !== false) {
            $this->OrgBlacklist = ClassRegistry::init('OrgBlacklist');
            if (!isset($cluster['GalaxyCluster']['Orgc']['uuid'])) {
                $orgc = $this->Orgc->find('first', array('conditions' => array('Orgc.id' => $cluster['GalaxyCluster']['orgc_id']), 'fields' => array('Orgc.uuid'), 'recursive' => -1));
            } else {
                $orgc = array('Orgc' => array('uuid' => $cluster['GalaxyCluster']['Orgc']['uuid']));
            }
            if ($cluster['GalaxyCluster']['orgc_id'] != 0 && $this->OrgBlacklist->hasAny(array('OrgBlacklist.org_uuid' => $orgc['Orgc']['uuid']))) {
                $results['failed']++; // Organisation blacklisted
                return $results;
            }
        }

        if ($cluster['GalaxyCluster']['default']) {
            $results['errors'][] = __('Can only save non default clusters');
            $results['failed']++;
            return $results;
        }

        $cluster = $this->captureOrganisationAndSG($cluster, 'GalaxyCluster', $user);
        $existingGalaxyCluster = $this->find('first', array('conditions' => array(
            'GalaxyCluster.uuid' => $cluster['GalaxyCluster']['uuid']
        )));
        $cluster['GalaxyCluster']['tag_name'] = sprintf('misp-galaxy:%s="%s"', $cluster['GalaxyCluster']['type'], $cluster['GalaxyCluster']['uuid']);
        if (!isset($cluster['GalaxyCluster']['published'])) {
            $cluster['GalaxyCluster']['published'] = false;
        }
        if (empty($existingGalaxyCluster)) {
            $galaxy = $this->Galaxy->captureGalaxy($user, $cluster['GalaxyCluster']['Galaxy']);
            $cluster['GalaxyCluster']['galaxy_id'] = $galaxy['Galaxy']['id'];
            $this->create();
            $saveSuccess = $this->save($cluster);
        } else {
            if ($fromPull && !$existingGalaxyCluster['GalaxyCluster']['locked'] && !$server['Server']['internal']) {
                $results['errors'][] = __('Blocked an edit to an cluster that was created locally. This can happen if a synchronised cluster that was created on this instance was modified by an administrator on the remote side.');
                $results['failed']++;
                return $results;
            }
            if ($cluster['GalaxyCluster']['version'] > $existingGalaxyCluster['GalaxyCluster']['version']) {
                $cluster['GalaxyCluster']['id'] = $existingGalaxyCluster['GalaxyCluster']['id'];
                $saveSuccess = $this->save($cluster);
            } else {
                $results['errors'][] = __('Remote version is not newer than local one for cluster (%s)', $cluster['GalaxyCluster']['uuid']);
                $results['ignored']++;
                return $results;
            }
        }
        if ($saveSuccess) {
            $results['imported']++;
            $savedCluster = $this->find('first', array(
                'conditions' => array('id' =>  $this->id),
                'recursive' => -1
            ));
            if (!empty($cluster['GalaxyCluster']['GalaxyElement'])) {
                $this->GalaxyElement->captureElements($user, $cluster['GalaxyCluster']['GalaxyElement'],  $savedCluster['GalaxyCluster']['id']);
            }
            if (!empty($cluster['GalaxyCluster']['GalaxyClusterRelation'])) {
                $saveResult = $this->GalaxyClusterRelation->captureRelations($user, $savedCluster, $cluster['GalaxyCluster']['GalaxyClusterRelation'],  $fromPull=$fromPull);
                if ($saveResult['failed'] > 0) {
                    $results['errors'][] = __('Issues while capturing relations have been logged.');
                }
            }
            if ($savedCluster['GalaxyCluster']['published']) {
                $this->publishRouter($user, $savedCluster['GalaxyCluster']['id'], $passAlong=$server['Server']['id']);
            }
        } else {
            $results['failed']++;
            foreach($this->validationErrors as $validationError) {
                $results['errors'][] = $validationError[0];
            }
        }
        $results['success'] = $results['imported'] > 0;
        return $results;
    }

    public function captureOrganisationAndSG($element, $model, $user)
    {
        $this->Event = ClassRegistry::init('Event');
        if (isset($element[$model]['distribution']) && $element[$model]['distribution'] == 4) {
            $element[$model] = $this->Event->__captureSGForElement($element[$model], $user);
        }
        // first we want to see how the creator organisation is encoded
        // The options here are either by passing an organisation object along or simply passing a string along
        if (isset($element[$model]['Orgc'])) {
            $element[$model]['orgc_id'] = $this->Orgc->captureOrg($element[$model]['Orgc'], $user);
            unset($element[$model]['Orgc']);
        } else {
            // Can't capture the Orgc, default to the current user
            $element[$model]['orgc_id'] = $user['org_id'];
        }
        return $element;
    }

    public function attachExtendByInfo($user, $cluster)
    {
        $extensions = $this->fetchGalaxyClusters($user, array('conditions' => array('extends_uuid' => $cluster['GalaxyCluster']['uuid'])));
        $cluster['GalaxyCluster']['extended_by'] = $extensions;
        return $cluster;
    }

    public function attachExtendFromInfo($user, $cluster)
    {
        if (!empty($cluster['GalaxyCluster']['extends_uuid'])) {
            $extensions = $this->fetchGalaxyClusters($user, array('conditions' => array('uuid' => $cluster['GalaxyCluster']['extends_uuid'])));
            if (!empty($extensions)) {
                $cluster['GalaxyCluster']['extended_from'] = $extensions[0];
            } else {
                $cluster['GalaxyCluster']['extended_from'] = array();
            }
        }
        return $cluster;
    }

    /* Return a list of all tags associated with the cluster specific cluster within the galaxy (or all clusters if $clusterValue is false)
     * The counts are restricted to the event IDs that the user is allowed to see.
    */
    public function getTags($galaxyType, $clusterValue = false, $user)
    {
        $this->Event = ClassRegistry::init('Event');
        $event_ids = $this->Event->fetchEventIds($user, false, false, false, true);
        $tags = $this->Event->EventTag->Tag->find('list', array(
                'conditions' => array('name LIKE' => 'misp-galaxy:' . $galaxyType . '="' . ($clusterValue ? $clusterValue : '%') .'"'),
                'fields' => array('name', 'id'),
        ));
        $this->Event->EventTag->virtualFields['tag_count'] = 'COUNT(id)';
        $tagCounts = $this->Event->EventTag->find('list', array(
                'conditions' => array('EventTag.tag_id' => array_values($tags), 'EventTag.event_id' => $event_ids),
                'fields' => array('EventTag.tag_id', 'EventTag.tag_count'),
                'group' => array('EventTag.tag_id')
        ));
        foreach ($tags as $k => $v) {
            if (isset($tagCounts[$v])) {
                $tags[$k] = array('count' => $tagCounts[$v], 'tag_id' => $v);
            } else {
                unset($tags[$k]);
            }
        }
        return $tags;
    }

    public function getCluster($name, $user)
    {
        if (isset($this->__clusterCache[$name])) {
            return $this->__clusterCache[$name];
        }
        $conditions = array();
        if (is_numeric($name)) {
            $conditions[] = array('GalaxyCluster.id' => $name);
        } else {
            $conditions[] = array('LOWER(GalaxyCluster.tag_name)' => strtolower($name));
        }
        $cluster = $this->fetchGalaxyClusters($user, array(
            'conditions' => $conditions,
            'first' => true
        ), true);

        if (!empty($cluster)) {
            $cluster = $this->postprocess($cluster);
        }
        if (!empty($cluster) && $cluster['GalaxyCluster']['default']) { // only cache default clusters
            $this->__clusterCache[$name] = $cluster;
        }
        return $cluster;
    }

    public function getClusters($names, $user, $postProcess=true)
    {
        $conditions = array();
        if (count(array_filter($names, 'is_numeric' )) === count($names)) { // all elements are numeric
            $conditions[] = array('GalaxyCluster.id' => $names);
        } else {
            $conditions[] = array('LOWER(GalaxyCluster.tag_name)' => array_map('strtolower', $names));
        }
        $clusters = $this->fetchGalaxyClusters($user, array(
            'conditions' => $conditions,
        ), true);

        if (!empty($clusters) && $postProcess) {
            foreach ($clusters as $k => $cluster) {
                $clusters[$k] = $this->postprocess($cluster);
            }
        }

        return $clusters;
    }

    public function buildConditions($user)
    {
        $this->Event = ClassRegistry::init('Event');
        $conditions = array();
        if (!$user['Role']['perm_site_admin']) {
            $sgids = $this->Event->cacheSgids($user, true);
            $alias = $this->alias;
            $conditions['AND']['OR'] = array(
                "${alias}.org_id" => $user['org_id'],
                array(
                    'AND' => array(
                        "${alias}.distribution >" => 0,
                        "${alias}.distribution <" => 4
                    ),
                ),
                array(
                    'AND' => array(
                        "${alias}.sharing_group_id" => $sgids,
                        "${alias}.distribution" => 4
                    )
                )
            );
        }
        return $conditions;
    }

    // very flexible, it's basically a replacement for find, with the addition that it restricts access based on user
    // options:
    //     fields
    //     contain
    //     conditions
    //     group
    public function fetchGalaxyClusters($user, $options, $full=false)
    {
        $params = array(
            'conditions' => $this->buildConditions($user),
            'recursive' => -1
        );
        if ($full) {
            $params['contain'] = array(
                'Galaxy',
                'GalaxyElement',
                'GalaxyClusterRelation' => array(
                    'conditions' => $this->GalaxyClusterRelation->buildConditions($user),
                    'GalaxyClusterRelationTag' => array('Tag'),
                    'SharingGroup'
                ),
                'TargetingClusterRelation' => array(
                    'conditions' => $this->TargetingClusterRelation->buildConditions($user),
                    'GalaxyClusterRelationTag' => array('Tag'),
                    'SharingGroup'
                ),
                'Orgc',
                'Org',
                'SharingGroup'
            );
        }
        if (!empty($options['contain'])) {
            $params['contain'] = $options['contain'];
        }
        if (isset($options['fields'])) {
            $params['fields'] = $options['fields'];
        }
        if (isset($options['conditions'])) {
            $params['conditions']['AND'][] = $options['conditions'];
        }
        if (isset($options['group'])) {
            $params['group'] = $options['group'];
        }
        if (isset($options['order'])) {
            $params['order'] = $options['order'];
        }
        if (isset($options['page'])) {
            $params['page'] = $options['page'];
        }
        if (isset($options['limit'])) {
            $params['limit'] = $options['limit'];
        }
        if (isset($options['list']) && $options['list']) {
            return $this->find('list', $params);
        }
        $clusters = array();
        if (isset($options['first']) && $options['first']) {
            $clusters = $this->find('first', $params);
        } else {
            $clusters = $this->find('all', $params);
        }
        $this->Event = ClassRegistry::init('Event');
        $sharingGroupData = $this->Event->__cacheSharingGroupData($user, false);
        foreach ($clusters as $i => $cluster) {
            if (!empty($cluster['GalaxyCluster']['sharing_group_id']) && isset($sharingGroupData[$cluster['GalaxyCluster']['sharing_group_id']])) {
                $clusters[$i]['SharingGroup'] = $sharingGroupData[$cluster['GalaxyCluster']['sharing_group_id']]['SharingGroup'];
            }
            if (isset($cluster['GalaxyClusterRelation'])) {
                foreach ($cluster['GalaxyClusterRelation'] as $j => $relation) {
                    if (!empty($relation['sharing_group_id']) && isset($sharingGroupData[$relation['sharing_group_id']])) {
                        $clusters[$i]['GalaxyClusterRelation'][$j]['SharingGroup'] = $sharingGroupData[$relation['sharing_group_id']]['SharingGroup'];
                    }
                }
            }
            $clusters[$i] = $this->arrangeData($clusters[$i]);
            $clusters[$i] = $this->GalaxyClusterRelation->massageRelationTag($clusters[$i]);
            $clusters[$i] = $this->TargetingClusterRelation->massageRelationTag($clusters[$i]);
        }
        return $clusters;
    }

    public function restSearch($user, $returnFormat, $filters, $paramsOnly=false, $jobId = false, &$elementCounter = 0)
    {
        if (!isset($this->validFormats[$returnFormat][1])) {
            throw new NotFoundException('Invalid output format.');
        }
        App::uses($this->validFormats[$returnFormat][1], 'Export');
        $exportTool = new $this->validFormats[$returnFormat][1]();
        $conditions = $this->buildFilterConditions($user, $filters);
        $params = array(
            'conditions' => $conditions,
            'full' => !empty($filters['full']) ? $filters['full'] : (!empty($filters['minimal']) ? !$filters['minimal'] : true),
            'minimal' => !empty($filters['minimal']) ? $filters['minimal'] : (!empty($filters['full']) ? !$filters['full'] : false),
        );

        if (isset($filters['limit'])) {
            $params['limit'] = $filters['limit'];
            if (!isset($filters['page'])) {
                $filters['page'] = 1;
            }
        }
        if (isset($filters['page'])) {
            $params['page'] = $filters['page'];
        }

        $default_cluster_memory_coefficient = 80;
        if ($params['full']) {
            $default_cluster_memory_coefficient = 0.5;
        }
        if ($params['minimal']) {
            $default_cluster_memory_coefficient = 100;
            $params['fields'] = array('uuid', 'version');
        }

        if ($paramsOnly) {
            return $params;
        }
        if (method_exists($exportTool, 'modify_params')) {
            $params = $exportTool->modify_params($user, $params);
        }
        $exportToolParams = array(
            'user' => $user,
            'params' => $params,
            'returnFormat' => $returnFormat,
            'scope' => 'GalaxyCluster',
            'filters' => $filters
        );
        if (!empty($exportTool->additional_params)) {
            $params = array_merge_recursive(
                $params,
                $exportTool->additional_params
            );
        }
        
        $tmpfile = tmpfile();
        fwrite($tmpfile, $exportTool->header($exportToolParams));
        $loop = false;
        if (empty($params['limit'])) {
            $memory_in_mb = $this->convert_to_memory_limit_to_mb(ini_get('memory_limit'));
            $memory_scaling_factor = $default_cluster_memory_coefficient / 10;
            $params['limit'] = intval($memory_in_mb * $memory_scaling_factor);
            $loop = true;
            $params['page'] = 1;
        }
        $this->__iteratedFetch($user, $params, $loop, $tmpfile, $exportTool, $exportToolParams, $elementCounter);
        fwrite($tmpfile, $exportTool->footer($exportToolParams));
        fseek($tmpfile, 0);
        if (fstat($tmpfile)['size']) {
            $final = fread($tmpfile, fstat($tmpfile)['size']);
        } else {
            $final = '';
        }
        fclose($tmpfile);
        return $final;
    }

    private function __iteratedFetch($user, &$params, &$loop, &$tmpfile, $exportTool, $exportToolParams, &$elementCounter = 0)
    {
        $continue = true;
        while ($continue) {
            $temp = '';
            $results = $this->fetchGalaxyClusters($user, $params, $full=$params['full']);
            if (empty($results)) {
                $loop = false;
                return true;
            }
            if ($elementCounter !== 0 && !empty($results)) {
                $temp .= $exportTool->separator($exportToolParams);
            }
            $params['page'] += 1;
            $i = 0;
            foreach ($results as $cluster) {
                $elementCounter++;
                $handlerResult = $exportTool->handler($cluster, $exportToolParams);
                $temp .= $handlerResult;
                if ($handlerResult !== '') {
                    if ($i != count($results) -1) {
                        $temp .= $exportTool->separator($exportToolParams);
                    }
                }
                $i++;
            }
            if (!$loop) {
                $continue = false;
            }
            fwrite($tmpfile, $temp);
        }
        return true;
    }

    public function buildFilterConditions($user, $filters)
    {
        $conditions = $this->buildConditions($user);
        if (isset($filters['org_id'])) {
            $this->Organisation = ClassRegistry::init('Organisation');
            if (!is_array($filters['org_id'])) {
                $filters['org_id'] = array($filters['org_id']);
            }
            foreach ($filters['org_id'] as $k => $org_id) {
                if (Validation::uuid($org_id)) {
                    $org = $this->Organisation->find('first', array('conditions' => array('Organisation.uuid' => $org_id), 'recursive' => -1, 'fields' => array('Organisation.id')));
                    if (empty($org)) {
                        $filters['org_id'][$k] = -1;
                    } else {
                        $filters['org_id'][$k] = $org['Organisation']['id'];
                    }
                }
            }
            $conditions['AND']['GalaxyCluster.org_id'] = $filters['org_id'];
        }
        if (isset($filters['orgc_id'])) {
            $this->Organisation = ClassRegistry::init('Organisation');
            if (!is_array($filters['orgc_id'])) {
                $filters['orgc_id'] = array($filters['orgc_id']);
            }
            foreach ($filters['orgc_id'] as $k => $orgc_id) {
                if (Validation::uuid($orgc_id)) {
                    $org = $this->Organisation->find('first', array('conditions' => array('Organisation.uuid' => $orgc_id), 'recursive' => -1, 'fields' => array('Organisation.id')));
                    if (empty($org)) {
                        $filters['orgc_id'][$k] = -1;
                    } else {
                        $filters['orgc_id'][$k] = $org['Organisation']['id'];
                    }
                }
            }
            $conditions['AND']['GalaxyCluster.orgc_id'] = $filters['orgc_id'];
        }

        if (isset($filters['galaxy_uuid'])) {
            $galaxyIds = $this->Galaxy->find('list', array(
                'recursive' => -1,
                'conditions' => array('Galaxy.uuid' => $filters['galaxy_uuid']),
                'fields' => array('id')
            ));
            if (!empty($galaxyIds)) {
                $filters['galaxy_id'] = array_values($galaxyIds);
            } else {
                $filters['galaxy_id'] = -1;
            }
        }

        if (isset($filters['eventid'])) {
            $clusterUUIDs = $this->getClusterUUIDsFromAttachedTags($user, $filters['eventid']);
            if (!empty($clusterUUIDs)) {
                $filters['uuid'] = array_values($clusterUUIDs);
            } else {
                $filters['uuid'] = -1;
            }
        }

        $simpleParams = array(
            'uuid', 'galaxy_id', 'version', 'distribution', 'type', 'value', 'default', 'extends_uuid', 'tag_name', 'published'
        );
        foreach ($simpleParams as $k => $simpleParam) {
            if (isset($filters[$simpleParam])) {
                $conditions['AND']["GalaxyCluster.${simpleParam}"] = $filters[$simpleParam];
            }
        }

        if (isset($filters['custom'])) {
            $conditions['AND']['GalaxyCluster.default'] = !$filters['custom'];
        }
        return $conditions;
    }

    private function getClusterUUIDsFromAttachedTags($user, $eventId)
    {
        $models = array('Attribute', 'Event');
        $clusterUUIDs = array();
        foreach ($models as $model) {
            $modelLower = strtolower($model);
            $joinCondition2 = array('table' => "${modelLower}_tags",
                'alias' => "${model}Tag",
                'type' => 'inner',
                'conditions' => array(
                    "Tag.id = ${model}Tag.tag_id",
                    "${model}Tag.event_id" => $eventId,
                )
            );
            if ($model == 'Attribute') {
                // We have to make sure users have access to the event/attributes
                // Otherwise, they might enumerate and  fetch tags from event/attributes they can't see
                $this->Attribute = ClassRegistry::init('Attribute');
                $attributes = $this->Attribute->fetchAttributes($user, array(
                    'conditions' => array('Attribute.event_id' => $eventId),
                    'fields' => array('Attribute.id'),
                    'flatten' => 1
                ));
                if (empty($attributes)) { // no attributes accessible
                    $attributes = -1;
                }
                $attributeIds = Hash::extract($attributes, '{n}.Attribute.id');
                $joinCondition2['conditions']["${model}Tag.attribute_id"] = $attributeIds;
            }
            $options = array(
                'joins' => array(
                    array('table' => 'tags',
                        'alias' => 'Tag',
                        'type' => 'inner',
                        'conditions' => array(
                            'GalaxyCluster.tag_name = Tag.name'
                        )
                    ),
                    $joinCondition2
                ),
                'fields' => array('GalaxyCluster.uuid'),
                'recursive' => -1,
            );
            $tmp = $this->find('list', $options);
            $clusterUUIDs = array_merge($clusterUUIDs, array_values($tmp));
        }
        $clusterUUIDs = array_unique($clusterUUIDs);
        return $clusterUUIDs;
    }

    public function fetchClusterById($user, $clusterId, $full=false)
    {
        $alias = $this->alias;
        if (Validation::uuid($clusterId)) {
            $temp = $this->find('first', array(
                'recursive' => -1,
                'fields' => array("${alias}.id", "${alias}.uuid"),
                'conditions' => array("${alias}.uuid" => $clusterId)
            ));
            if ($temp === null) {
                throw new NotFoundException(__('Invalid galaxy cluster'));
            }
            $clusterId = $temp[$alias]['id'];
        } elseif (!is_numeric($clusterId)) {
            throw new NotFoundException(__('Invalid galaxy cluster'));
        }
        $conditions = array('conditions' => array("${alias}.id" => $clusterId));
        $cluster = $this->fetchGalaxyClusters($user, $conditions, $full);
        return $cluster;
    }


    public function checkAuthorization($user, $cluster, $authorizations, $throwErrors=true, $full=false)
    {
        $authorizations = is_array($authorizations) ? $authorizations : array($authorizations);
        $possibleAuthorizations = array('view', 'edit', 'delete', 'publish');
        if (!empty(array_diff($authorizations, $possibleAuthorizations))) {
            throw new NotFoundException(__('Invalid authorization requested'));
        }
        if (!isset($cluster[$this->alias]['uuid'])) {
            $cluster = $this->fetchClusterById($user, $cluster, $full=$full);
            if (empty($cluster)) {
                $message = __('Invalid galaxy cluster');
                if ($throwErrors) {
                    throw new NotFoundException($message);
                }
                return array('authorized' => false, 'error' => $message);
            }
        }
        $cluster = $cluster[0];
        if ($user['Role']['perm_site_admin']) {
            return $cluster;
        }

        if (in_array('view', $authorizations) && count($authorizations) == 1) {
            return $cluster;
        } else {
            if (!$user['Role']['perm_galaxy_editor']) {
                $message = __('You don\'t have the permission to do that.');
                if ($throwErrors) {
                    throw new MethodNotAllowedException($message);
                }
                return array('authorized' => false, 'error' => $message);
            }
            if (in_array('edit', $authorizations) || in_array('delete', $authorizations)) {
                if ($cluster[$this->alias]['orgc_id'] != $user['org_id']) {
                    $message = __('Only the creator organisation can modify the galaxy cluster');
                    if ($throwErrors) {
                        throw new MethodNotAllowedException($message);
                    }
                    return array('authorized' => false, 'error' => $message);
                }
            }
            if (in_array('publish', $authorizations)) {
                if ($cluster[$this->alias]['orgc_id'] != $user['org_id']) {
                    $message = __('Only the creator organisation can publish the galaxy cluster');
                    if ($throwErrors) {
                        throw new MethodNotAllowedException($message);
                    }
                    return array('authorized' => false, 'error' => $message);
                }
            }
            return $cluster;
        }
    }

    /**
     * @param array $events
     * @param bool $replace
     * @return array
     */
    public function attachClustersToEventIndex($user, array $events, $replace = false)
    {
        $clusterTagNames = array();
        foreach ($events as $event) {
            foreach ($event['EventTag'] as $k2 => $eventTag) {
                if (substr($eventTag['Tag']['name'], 0, strlen('misp-galaxy:')) === 'misp-galaxy:') {
                    $clusterTagNames[] = $eventTag['Tag']['name'];
                }
            }
        }

        $clusters = $this->getClusters($clusterTagNames, $user, false);

        $clustersByTagName = array();
        foreach ($clusters as $cluster) {
            $clustersByTagName[strtolower($cluster['GalaxyCluster']['tag_name'])] = $cluster;
        }

        foreach ($events as $k => $event) {
            foreach ($event['EventTag'] as $k2 => $eventTag) {
                $tagName = strtolower($eventTag['Tag']['name']);
                if (isset($clustersByTagName[$tagName])) {
                    $cluster = $this->postprocess($clustersByTagName[$tagName], $eventTag['Tag']['id']);
                    $cluster['GalaxyCluster']['tag_id'] = $eventTag['Tag']['id'];
                    $cluster['GalaxyCluster']['local'] = $eventTag['local'];
                    $events[$k]['GalaxyCluster'][] = $cluster['GalaxyCluster'];
                    if ($replace) {
                        unset($events[$k]['EventTag'][$k2]);
                    }
                }
            }
        }
        return $events;
    }

    /**
     * @param array $cluster
     * @param int|null $tagId
     * @return array
     */
    private function postprocess(array $cluster, $tagId = null)
    {
        $cluster = $this->arrangeData($cluster);

        $elements = array();
        foreach ($cluster['GalaxyCluster']['GalaxyElement'] as $element) {
            if (!isset($elements[$element['key']])) {
                $elements[$element['key']] = array($element['value']);
            } else {
                $elements[$element['key']][] = $element['value'];
            }
        }
        unset($cluster['GalaxyCluster']['GalaxyElement']);
        $cluster['GalaxyCluster']['meta'] = $elements;

        if ($tagId) {
            $cluster['GalaxyCluster']['tag_id'] = $tagId;
        } else {
            $this->Tag = ClassRegistry::init('Tag');
            $tag_id = $this->Tag->find(
                'first',
                array(
                    'conditions' => array(
                        'LOWER(Tag.name)' => strtolower($cluster['GalaxyCluster']['tag_name'])
                    ),
                    'recursive' => -1,
                    'fields' => array('Tag.id')
                )
            );
            if (!empty($tag_id)) {
                $cluster['GalaxyCluster']['tag_id'] = $tag_id['Tag']['id'];
            }
        }

        return $cluster;
    }

    public function getClusterTagsFromMeta($galaxyElements, $user)
    {
        // AND operator between cluster metas
        $tmpResults = array();
        foreach ($galaxyElements as $galaxyElementKey => $galaxyElementValue) {
            $tmpResults[] = array_values($this->GalaxyElement->find('list', array(
                'conditions' => array(
                    'LOWER(GalaxyElement.key)' => strtolower($galaxyElementKey),
                    'LOWER(GalaxyElement.value)' => strtolower($galaxyElementValue),
                ),
                'fields' => array('galaxy_cluster_id'),
                'recursive' => -1
            )));
        }
        $clusterTags = array();
        if (!empty($tmpResults)) {
            // Get all Clusters matching all conditions
            $matchingClusters = $tmpResults[0];
            array_shift($tmpResults);
            foreach ($tmpResults as $tmpResult) {
                $matchingClusters = array_intersect($matchingClusters, $tmpResult);
            }
            $clusterTags = $this->fetchGalaxyClusters($user, array(
                'conditions' => array('id' => $matchingClusters),
                'fields' => array('GalaxyCluster.tag_name'),
                'list' => true,
            ), $full=false);
        }
        return array_values($clusterTags);
    }

    public function getElligibleClustersToPush($user, $conditions=array(), $full=false)
    {
        $options = array(
            'conditions' => array(
                'GalaxyCluster.default' => 0,
                'GalaxyCluster.published' => 1,
            ),
        );
        $options['conditions'] = array_merge($options['conditions'], $conditions);
        if (!$full) {
            $options['fields'] = array('uuid', 'version');
            $options['list'] = true;
        }
        $clusters = $this->fetchGalaxyClusters($user, $options, $full=$full);
        return $clusters;
    }

    public function getElligibleLocalClustersToUpdate($user)
    {
        $options = array(
            'conditions' => array(
                'GalaxyCluster.default' => 0,
                'GalaxyCluster.locked' => 1,
            ),
            'fields' => array('uuid', 'version'),
            'list' => true,
        );
        $clusters = $this->fetchGalaxyClusters($user, $options, $full=false);
        return $clusters;
    }

    public function uploadClusterToServer($cluster, $server, $HttpSocket, $user)
    {
        $this->Server = ClassRegistry::init('Server');
        $this->Log = ClassRegistry::init('Log');
        $push = $this->Server->checkVersionCompatibility($server['Server']['id'], false, $HttpSocket);
        if (empty($push['canPush']) && empty($push['canPushGalaxyCluster'])) {
            return 'The remote user is not a sightings user - the upload of the galaxy clusters has been blocked.';
        }
        $updated = null;
        $newLocation = $newTextBody = '';
        $result = $this->__executeRestfulGalaxyClusterToServer($cluster, $server, null, $newLocation, $newTextBody, $HttpSocket, $user);
        if ($result !== true) {
            return $result;
        }
        if (strlen($newLocation)) { // HTTP/1.1 302 Found and Location: http://<newLocation>
            $result = $this->__executeRestfulGalaxyClusterToServer($cluster, $server, $newLocation, $newLocation, $newTextBody, $HttpSocket, $user);
            if ($result !== true) {
                return $result;
            }
        }
        $uploadFailed = false;
        try {
            $json = json_decode($newTextBody, true);
        } catch (Exception $e) {
            $uploadFailed = true;
        }
        if (!is_array($json) || $uploadFailed) {
            $this->Log->createLogEntry($user, 'push', 'GalaxyCluster', $cluster['GalaxyCluster']['id'], 'push', $newTextBody);
        }
        return 'Success';
    }

    private function __executeRestfulGalaxyClusterToServer($cluster, $server, $resourceId, &$newLocation, &$newTextBody, $HttpSocket, $user)
    {
        $result = $this->restfulGalaxyClusterToServer($cluster, $server, $resourceId, $newLocation, $newTextBody, $HttpSocket);
        if (is_numeric($result)) {
            $error = $this->__resolveErrorCode($result, $cluster, $server, $user);
            if ($error) {
                return $error . ' Error code: ' . $result;
            }
        }
        return true;
    }

    public function restfulGalaxyClusterToServer($cluster, $server, $urlPath, &$newLocation, &$newTextBody, $HttpSocket = null)
    {
        $cluster = $this->__prepareForPushToServer($cluster, $server);
        if (is_numeric($cluster)) {
            return $cluster;
        }
        $url = $server['Server']['url'];
        $HttpSocket = $this->setupHttpSocket($server, $HttpSocket);
        $request = $this->setupSyncRequest($server);
        $scope = 'galaxies/pushCluster';
        $uri = $url . '/' . $scope;
        $clusters = array($cluster);
        $data = json_encode($clusters);
        if (!empty(Configure::read('Security.sync_audit'))) {
            $pushLogEntry = sprintf(
                "==============================================================\n\n[%s] Pushing Galaxy Cluster #%d to Server #%d:\n\n%s\n\n",
                date("Y-m-d H:i:s"),
                $cluster['GalaxyCluster']['id'],
                $server['Server']['id'],
                $data
            );
            file_put_contents(APP . 'files/scripts/tmp/debug_server_' . $server['Server']['id'] . '.log', $pushLogEntry, FILE_APPEND);
        }
        $response = $HttpSocket->post($uri, $data, $request);
        return $this->__handleRestfulGalaxyClusterToServerResponse($response, $newLocation, $newTextBody);
    }

    private function __prepareForPushToServer($cluster, $server)
    {
        if ($cluster['GalaxyCluster']['distribution'] == 4) {
            if (!empty($cluster['GalaxyCluster']['SharingGroup']['SharingGroupServer'])) {
                $found = false;
                foreach ($cluster['GalaxyCluster']['SharingGroup']['SharingGroupServer'] as $sgs) {
                    if ($sgs['server_id'] == $server['Server']['id']) {
                        $found = true;
                    }
                }
                if (!$found) {
                    return 403;
                }
            } else if (empty($cluster['GalaxyCluster']['SharingGroup']['roaming'])) {
                return 403;
            }
        }
        $this->Event = ClassRegistry::init('Event');
        if ($this->Event->checkDistributionForPush($cluster, $server, 'GalaxyCluster')) {
            $cluster = $this->__updateClusterForSync($cluster, $server);
        } else {
            return 403;
        }
        return $cluster;
    }

    private function __updateClusterForSync($cluster, $server)
    {
        $this->Event = ClassRegistry::init('Event');
        // cleanup the array from things we do not want to expose
        foreach (array('org_id', 'orgc_id', 'id', 'galaxy_id') as $field) {
            unset($cluster['GalaxyCluster'][$field]);
        }
        // Add the local server to the list of instances in the SG
        if (isset($cluster['GalaxyCluster']['SharingGroup']) && isset($cluster['GalaxyCluster']['SharingGroup']['SharingGroupServer'])) {
            foreach ($cluster['GalaxyCluster']['SharingGroup']['SharingGroupServer'] as &$s) {
                if ($s['server_id'] == 0) {
                    $s['Server'] = array(
                        'id' => 0,
                        'url' => $this->Event->__getAnnounceBaseurl(),
                        'name' => $this->Event->__getAnnounceBaseurl()
                    );
                }
            }
        }
        $cluster = $this->__prepareElementsForSync($cluster, $server);
        $cluster = $this->__prepareRelationsForSync($cluster, $server);

        // Downgrade the event from connected communities to community only
        if (!$server['Server']['internal'] && $cluster['GalaxyCluster']['distribution'] == 2) {
            $cluster['GalaxyCluster']['distribution'] = 1;
        }
        return $cluster;
    }

    private function __prepareElementsForSync($cluster, $server)
    {
        if (!empty($cluster['GalaxyCluster']['GalaxyElement'])) {
            foreach($cluster['GalaxyCluster']['GalaxyElement'] as $k => $element) {
                $cluster['GalaxyCluster']['GalaxyElement'][$k] = $this->__updateElementForSync($element, $server);
            }
        }
        return $cluster;
    }

    private function __prepareRelationsForSync($cluster, $server)
    {
        $this->Event = ClassRegistry::init('Event');
        if (!empty($cluster['GalaxyCluster']['GalaxyClusterRelation'])) {
            foreach($cluster['GalaxyCluster']['GalaxyClusterRelation'] as $k => $relation) {
                $cluster['GalaxyCluster']['GalaxyClusterRelation'][$k] = $this->__updateRelationsForSync($relation, $server);
                if (empty($cluster['GalaxyCluster']['GalaxyClusterRelation'][$k])) {
                    unset($cluster['GalaxyCluster']['GalaxyClusterRelation'][$k]);
                } else {
                    $cluster['GalaxyCluster']['GalaxyClusterRelation'][$k] = $this->Event->__removeNonExportableTags($cluster['GalaxyCluster']['GalaxyClusterRelation'][$k], 'GalaxyClusterRelation');
                }
            }
            $cluster['GalaxyCluster']['GalaxyClusterRelation'] = array_values($cluster['GalaxyCluster']['GalaxyClusterRelation']);
        }
        return $cluster;
    }

    private function __updateElementForSync($element, $server)
    {
        unset($element['id']);
        unset($element['galaxy_cluster_id']);
        return $element;
    }

    private function __updateRelationsForSync($relation, $server)
    {
        // do not keep attributes that are private, nor cluster
        if (!$server['Server']['internal'] && $relation['distribution'] < 2) {
            return false;
        }
        // Downgrade the attribute from connected communities to community only
        if (!$server['Server']['internal'] && $relation['distribution'] == 2) {
            $relation['distribution'] = 1;
        }

        $this->Event = ClassRegistry::init('Event');
        // If the attribute has a sharing group attached, make sure it can be transferred
        if ($relation['distribution'] == 4) {
            if (!$server['Server']['internal'] && $this->Event->checkDistributionForPush(array('GalaxyClusterRelation' => $relation), $server, 'GalaxyClusterRelation') === false) {
                return false;
            }
            // Add the local server to the list of instances in the SG
            if (!empty($relation['SharingGroup']['SharingGroupServer'])) {
                foreach ($relation['SharingGroup']['SharingGroupServer'] as &$s) {
                    if ($s['server_id'] == 0) {
                        $s['Server'] = array(
                            'id' => 0,
                            'url' => $this->__getAnnounceBaseurl(),
                            'name' => $this->__getAnnounceBaseurl()
                        );
                    }
                }
            }
        }
        unset($relation['id']);
        unset($relation['galaxy_cluster_id']);
        unset($relation['referenced_galaxy_cluster_id']);
        return $relation;
    }

    private function __handleRestfulGalaxyClusterToServerResponse($response, &$newLocation, &$newTextBody)
    {
        switch ($response->code) {
            case '200': // 200 (OK) + entity-action-result
                if ($response->isOk()) {
                    $newTextBody = $response->body();
                    return true;
                } else {
                    try {
                        $jsonArray = json_decode($response->body, true);
                    } catch (Exception $e) {
                        return true;
                    }
                    return $jsonArray['name'];
                }
                // no break
            case '302': // Found
                $newLocation = $response->headers['Location'];
                $newTextBody = $response->body();
                return true;
            case '404': // Not Found
                $newLocation = $response->headers['Location'];
                $newTextBody = $response->body();
                return 404;
            case '405':
                return 405;
            case '403': // Not authorised
                return 403;
        }
    }

    private function __resolveErrorCode($code, &$cluster, &$server, $user)
    {
        $this->Log = ClassRegistry::init('Log');
        $error = false;
        switch ($code) {
            case 403:
                return 'The distribution level of the cluster blocks it from being pushed.';
            case 405:
                $error = 'The sync user on the remote instance does not have the required privileges to handle this cluster.';
                break;
        }
        if ($error) {
            $newTextBody = 'Uploading GalaxyCluster (' . $cluster['GalaxyCluster']['id'] . ') to Server (' . $server['Server']['id'] . ')';
            $this->Log->createLogEntry($user, 'push', 'GalaxyCluster', $cluster['GalaxyCluster']['id'], 'push', $newTextBody);
        }
        return $error;
    }

    public function pullGalaxyClusters($user, $server, $technique = 'full')
    {
        $version = explode('.', $server['Server']['version']);
        if (
            ($version[0] == 2 && $version[1] == 4 && $version[2] < 111) // FIXME: Use correct version
        ) {
            return 0;
        }
        $clusterIds = $this->__getClusterIdListBasedOnPullTechnique($user, $technique, $server);
        if (!isset($server['Server']['version'])) {
            $this->Server = ClassRegistry::init('Server');
            $server['Server']['version'] = $this->Server->getRemoteVersion($server['Server']['id']);
        }
        $successes = array();
        $fails = array();
        // now process the $clusterIds to pull each of the events sequentially
        if (!empty($clusterIds)) {
            // download each cluster
            foreach ($clusterIds as $k => $clusterId) {
                $this->__pullGalaxyCluster($clusterId, $successes, $fails, $server, $user);
            }
        }
        return count($successes);
    }

    private function __getClusterIdListBasedOnPullTechnique($user, $technique, $server)
    {
        $this->Server = ClassRegistry::init('Server');
        if ("update" === $technique) {
            $localClustersToUpdate = $this->getElligibleLocalClustersToUpdate($user);
            $clusterIds = $this->Server->getElligibleClusterIdsFromServerForPull($server, $HttpSocket=null, $onlyUpdateLocalCluster=true, $elligibleClusters=$localClustersToUpdate);
        } elseif ("pull_relevant_clusters" === $technique) {
            // Fetch all local custom cluster tags then fetch their corresponding clusters on the remote end
            $tagNames = $this->Tag->find('list', array(
                'conditions' => array(
                    'Tag.is_custom_galaxy' => true
                ),
                'fields' => array('Tag.name'),
                'recursive' => -1,
            ));
            $clusterUUIDs = array();
            $re = '/^misp-galaxy:[^:="]+="(?<uuid>[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12})"$/m';
            foreach ($tagNames as $tagName) {
                preg_match($re, $tagName, $matches);
                if (isset($matches['uuid'])) {
                    $clusterUUIDs[$matches['uuid']] = true;
                }
            }
            $localClustersToUpdate = $this->getElligibleLocalClustersToUpdate($user);
            $conditions = array('uuid' => array_keys($clusterUUIDs));
            $clusterIds = $this->Server->getElligibleClusterIdsFromServerForPull($server, $HttpSocket=null, $onlyUpdateLocalCluster=false, $elligibleClusters=$localClustersToUpdate, $conditions=$conditions);
        } elseif (is_numeric($technique)) {
            $conditions = array('eventid' => $technique);
            $clusterIds = $this->Server->getElligibleClusterIdsFromServerForPull($server, $HttpSocket=null, $onlyUpdateLocalCluster=false, $elligibleClusters=array(), $conditions=$conditions);
        } else {
            $clusterIds = $this->Server->getElligibleClusterIdsFromServerForPull($server, $HttpSocket=null, $onlyUpdateLocalCluster=false);
        }
        if ($clusterIds === 403) {
            return array('error' => array(1, null));
        } elseif (is_string($clusterIds)) {
            return array('error' => array(2, $clusterIds));
        }
        return $clusterIds;
    }

    private function __pullGalaxyCluster($clusterId, &$successes, &$fails, $server, $user)
    {
        $cluster = $this->downloadGalaxyClusterFromServer($clusterId, $server);
        if (!empty($cluster)) {
            $cluster = $this->__updatePulledClusterBeforeInsert($cluster, $server, $user);
            $result = $this->captureCluster($user, $cluster, $fromPull=true, $orgId=$server['Server']['org_id']);
            if ($result['success']) {
                $successes[] = $clusterId;
            } else {
                $fails[$clusterId] = __('Failed because of errors: ') . json_encode($result['errors']);
            }
        } else {
            $fails[$clusterId] = __('failed downloading the galaxy cluster');
        }
        return true;
    }

    public function downloadGalaxyClusterFromServer($clusterId, $server, $HttpSocket=null)
    {
        $url = $server['Server']['url'];
        $HttpSocket = $this->setupHttpSocket($server, $HttpSocket);
        $request = $this->setupSyncRequest($server);
        $uri = $url . '/galaxy_clusters/view/' . $clusterId;
        $response = $HttpSocket->get($uri, $data = '', $request);
        if ($response->isOk()) {
            return json_decode($response->body, true);
        }
        return null;
    }

    private function __updatePulledClusterBeforeInsert($cluster, $server, $user)
    {
        // The cluster came from a pull, so it should be locked and distribution should be adapted.
        $cluster['GalaxyCluster']['locked'] = true;
        if (!isset($cluster['GalaxyCluster']['distribution'])) {
            $cluster['GalaxyCluster']['distribution'] = '1';
        }

        if (empty(Configure::read('MISP.host_org_id')) || !$server['Server']['internal'] || Configure::read('MISP.host_org_id') != $server['Server']['org_id']) {
            switch ($cluster['GalaxyCluster']['distribution']) {
                case 1:
                    $cluster['GalaxyCluster']['distribution'] = 0; // if community only, downgrade to org only after pull
                    break;
                case 2:
                    $cluster['GalaxyCluster']['distribution'] = 1; // if connected communities downgrade to community only
                    break;
            }

            if (!empty($cluster['GalaxyCluster']['GalaxyClusterRelation'])) {
                foreach ($cluster['GalaxyCluster']['GalaxyClusterRelation'] as $k => $relation) {
                    switch ($relation['distribution']) {
                        case 1:
                            $cluster['GalaxyCluster']['GalaxyClusterRelation'][$k]['distribution'] = 0;
                            break;
                        case 2:
                            $cluster['GalaxyCluster']['GalaxyClusterRelation'][$k]['distribution'] = 1;
                            break;
                    }
                }
            }
        }
        return $cluster;
    }

    public function attachClusterToRelations($user, $cluster)
    {
        if (!empty($cluster['GalaxyCluster']['GalaxyClusterRelation'])) {
            foreach ($cluster['GalaxyCluster']['GalaxyClusterRelation'] as $k => $relation) {
                $conditions = array('conditions' => array('GalaxyCluster.uuid' => $relation['referenced_galaxy_cluster_uuid']));
                $relatedCluster = $this->fetchGalaxyClusters($user, $conditions, false);
                if (!empty($relatedCluster)) {
                    $cluster['GalaxyCluster']['GalaxyClusterRelation'][$k]['GalaxyCluster'] = $relatedCluster[0]['GalaxyCluster'];
                }
            }
        }
        if (!empty($cluster['GalaxyCluster']['TargetingClusterRelation'])) {
            foreach ($cluster['GalaxyCluster']['TargetingClusterRelation'] as $k => $relation) {
                $conditions = array('conditions' => array('GalaxyCluster.uuid' => $relation['galaxy_cluster_uuid']));
                $relatedCluster = $this->fetchGalaxyClusters($user, $conditions, false);
                if (!empty($relatedCluster)) {
                    $cluster['GalaxyCluster']['TargetingClusterRelation'][$k]['GalaxyCluster'] = $relatedCluster[0]['GalaxyCluster'];
                }
            }
        }
        return $cluster;
    }

    public function attachTargettingRelations($user, $cluster)
    {
        $targettingRelations = $this->GalaxyClusterRelation->fetchRelations($user, array(
            'conditions' => array(
                'referenced_galaxy_cluster_uuid' => $cluster['GalaxyCluster']['uuid']
            ),
            'contain' => array('GalaxyClusterRelationTag' => 'Tag')
        ));
        if (!empty($targettingRelations)) {
            foreach ($targettingRelations as $k => $relation) {
                if (!empty($relation['GalaxyClusterRelationTag'])) {
                    $relation['GalaxyClusterRelation']['Tag'] = $relation['GalaxyClusterRelationTag'][0]['Tag'];
                }
                $cluster['GalaxyCluster']['TargetingClusterRelation'][] = $relation['GalaxyClusterRelation'];
            }
        }
        return $cluster;
    }

    public function cacheGalaxyClusterIDs($user)
    {
        if (isset($this->__assetCache['gcids'])) {
            return $this->__assetCache['gcids'];
        } else {
            $gcids = $this->fetchGalaxyClusters($user, array(
                'fields' => 'id',
                'conditions' => array(
                    'org_id' => $user['org_id']
                )
            ), false);
            $alias = $this->alias;
            $gcids = Hash::extract($gcids, "{n}.${alias}.id");
            if (empty($gcids)) {
                $gcids = array(-1);
            }
            $this->__assetCache['gcids'] = $gcids;
            return $gcids;
        }
    }
}
