<?php

/*
 * Pterodactyl 2.0 for Paymenter
 *
 * A drop-in replacement for Paymenter's built-in Pterodactyl server extension that works with
 * Pterodactyl 1.x and Pterodactyl 2.0. Pterodactyl 2.0 removed the Application API's nest
 * endpoints (/api/application/nests/...), which the built-in extension needs, so servers can no
 * longer be created or upgraded after a panel upgrade. This extension detects the panel version
 * and uses /api/application/eggs and tags on 2.0, and nests on 1.x.
 *
 * Based on the built-in Pterodactyl extension from Paymenter (MIT, Copyright (c) Paymenter).
 * Changes Copyright (c) 2026 Karelia Labs. Released under the MIT License, see LICENSE.
 */

namespace Paymenter\Extensions\Servers\Pterodactyl2;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

#[ExtensionMeta(
    name: 'Pterodactyl 2.0',
    description: 'Pterodactyl 1.x and 2.0 server extension. Drop-in replacement for the built-in Pterodactyl extension.',
    version: '1.0.1',
    author: 'Karelia Labs',
    url: 'https://github.com/ziroxonline111/paymenter-pterodactyl-v2',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+PHJlY3Qgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0IiByeD0iMTQiIGZpbGw9IiMxMDUyOUYiLz48dGV4dCB4PSIzMiIgeT0iNDEiIGZvbnQtZmFtaWx5PSJBcmlhbCxIZWx2ZXRpY2Esc2Fucy1zZXJpZiIgZm9udC1zaXplPSIyNCIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+UDI8L3RleHQ+PC9zdmc+',
)]
class Pterodactyl2 extends Server
{
    /** How long the detected panel version is remembered. */
    private const GENERATION_TTL_MINUTES = 10;

    /** Optional add-on that adds single sign-on and billing info to the panel. */
    private const BRIDGE_CLASS = 'Paymenter\\Extensions\\Others\\KareliaBridge\\KareliaBridge';

    public function getConfig($values = []): array
    {
        // The keys match the built-in extension, so an existing server can switch over without re-entering anything.
        return [
            [
                'name' => 'host',
                'label' => 'Pterodactyl URL',
                'type' => 'text',
                'description' => 'Pterodactyl URL',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'Pterodactyl API Key',
                'type' => 'text',
                'description' => 'Application API key (Admin → API). Read and write access to servers, users, nodes, locations, allocations and eggs.',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            Cache::forget($this->generationCacheKey());
            $this->request('/api/application/servers', 'get', ['per_page' => 1]);
            $this->generation();
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function request($url, $method = 'get', $data = []): array
    {
        $response = $this->send($url, $method, $data);

        if (!$response->successful()) {
            throw new Exception($this->errorMessage($response));
        }

        return $response->json() ?? [];
    }

    /**
     * Pterodactyl 2.0 answers /api/application/eggs, 1.x does not have that route.
     */
    public function generation(): int
    {
        return Cache::remember($this->generationCacheKey(), now()->addMinutes(self::GENERATION_TTL_MINUTES), function () {
            $response = $this->send('/api/application/eggs', 'get', ['per_page' => 1]);
            if ($response->successful()) {
                return 2;
            }
            if ($response->status() === 404 || $response->status() === 405) {
                return 1;
            }

            throw new Exception($this->errorMessage($response));
        });
    }

    public function getProductConfig($values = []): array
    {
        $nodeList = [];
        foreach ($this->all('/api/application/nodes') as $node) {
            $nodeList[$node['attributes']['id']] = $node['attributes']['name'];
        }

        $locationList = [];
        foreach ($this->all('/api/application/locations') as $location) {
            $locationList[$location['attributes']['id']] = $location['attributes']['short'];
        }

        $eggFields = $this->generation() === 2 ? $this->eggFieldsV2($values) : $this->eggFieldsV1($values);

        $using_port_array = isset($values['port_array']) && $values['port_array'] !== '';

        return [
            [
                'name' => 'location_ids',
                'label' => 'Location(s)',
                'type' => 'select',
                'description' => 'Location(s) where the server will be installed',
                'options' => $locationList,
                'multiple' => true,
                'database_type' => 'array',
                'required' => false,
            ],
            [
                'name' => 'node',
                'label' => 'Node',
                'type' => 'select',
                'description' => 'Fill in to install the server on a specific node',
                'options' => $nodeList,
            ],
            ...$eggFields,
            [
                'name' => 'memory',
                'label' => 'Memory',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'validation' => 'numeric',
                'min_value' => 0,
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'swap',
                'label' => 'Swap',
                'type' => 'number',
                'min_value' => -1,
                'suffix' => 'MiB',
                'required' => true,
                'description' => 'Set to -1 for unlimited, or to 0 to disable swap',
            ],
            [
                'name' => 'disk',
                'label' => 'Disk',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'min_value' => 0,
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'io',
                'label' => 'IO Weight',
                'type' => 'number',
                'required' => true,
                'default' => 500,
                'min_value' => 10,
                'max_value' => 1000,
                'description' => 'The IO Weight is the priority given to this server for disk access.',
                'hint' => new HtmlString('<a href="https://docs.docker.com/engine/reference/run/#block-io-bandwidth-blkio-constraint" target="_blank">Documentation</a>'),
            ],
            [
                'name' => 'cpu',
                'label' => 'CPU Limit',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
                'suffix' => '%',
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'cpu_pinning',
                'label' => 'CPU Pinning',
                'type' => 'text',
                'description' => 'Leave empty for no pinning. Used to specify what threads should be used. Example: 0,2-4,5,6',
                'validation' => 'regex:/^[0-9]+(?:-[0-9]+)?(?:,[0-9]+(?:-[0-9]+)?)*$/',
            ],
            [
                'name' => 'databases',
                'label' => 'Databases',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
            ],
            [
                'name' => 'backups',
                'label' => 'Backups',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
            ],
            [
                'name' => 'additional_allocations',
                'label' => 'Additional Allocations',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
            ],
            [
                'name' => 'port_array',
                'label' => 'Port Array',
                'type' => 'text',
                'description' => 'Used to assign ports to egg variables.',
                'hint' => new HtmlString('<a href="https://paymenter.org/docs/extensions/pterodactyl#port-array" target="_blank">Documentation</a>'),
                'live' => true,
                'validation' => 'json',
            ],
            [
                'name' => 'port_range',
                'label' => 'Port ranges',
                'type' => 'tags',
                'description' => '',
                'database_type' => 'array',
                'required' => false,
                'disabled' => $using_port_array,
            ],
            [
                'name' => 'skip_scripts',
                'label' => 'Skip Egg Install Script',
                'description' => 'If the selected Egg has an install script attached to it, the script will run during the install. If you would like to skip this step, check this box.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'dedicated_ip',
                'label' => 'Dedicated IP',
                'description' => 'Assigns the server an allocation whose IP is not being used by any other server.',
                'type' => 'checkbox',
                'disabled' => $using_port_array,
            ],
            [
                'name' => 'start_on_completion',
                'label' => 'Start on completion',
                'description' => 'Start server automatically after installation.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'oom_killer',
                'label' => 'Enable OOM Killer',
                'description' => 'Terminates the server if it breaches the memory limits. Enabling OOM killer may cause server processes to exit unexpectedly.',
                'type' => 'checkbox',
            ],
        ];
    }

    /**
     * Pterodactyl 1.x: pick a nest, then one of its eggs (same fields as the built-in extension).
     */
    private function eggFieldsV1(array $values): array
    {
        $nestList = [];
        foreach ($this->all('/api/application/nests') as $nest) {
            $nestList[$nest['attributes']['id']] = $nest['attributes']['name'];
        }

        $eggList = [];
        if (isset($values['nest_id']) && $values['nest_id'] !== '') {
            try {
                foreach ($this->all('/api/application/nests/' . $values['nest_id'] . '/eggs') as $egg) {
                    $eggList[$egg['attributes']['id']] = $egg['attributes']['name'];
                }
            } catch (Exception $e) {
            }
        }

        return [
            [
                'name' => 'nest_id',
                'label' => 'Nest ID',
                'type' => 'select',
                'options' => $nestList,
                'description' => 'Nest ID to fetch the eggs from',
                'required' => true,
                'live' => true,
            ],
            [
                'name' => 'egg_id',
                'label' => 'Egg ID',
                'type' => 'select',
                'options' => $eggList,
                'required' => true,
            ],
        ];
    }

    /**
     * Pterodactyl 2.0: nests are gone. Eggs are listed directly and can be narrowed down by tag.
     * Egg IDs did not change in the upgrade, so products set up on 1.x keep their egg.
     */
    private function eggFieldsV2(array $values): array
    {
        $tagList = [];
        $tagOptions = [];
        $tagByLegacyNest = [];
        try {
            $tags = $this->all('/api/application/tags');
        } catch (Exception $e) {
            // An API key without read access to tags still gets the full egg list.
            $tags = [];
        }
        foreach ($tags as $tag) {
            $attributes = $tag['attributes'];
            $tagList[$attributes['slug']] = $attributes['name'];
            $tagOptions[$attributes['slug']] = $attributes['name'];
            if (!empty($attributes['legacy_nest_id'])) {
                $tagByLegacyNest[$attributes['legacy_nest_id']] = $attributes['slug'];
            }
        }

        // A product created on 1.x has a nest_id: start the filter on the tag that replaced that nest.
        $selectedTag = $values['egg_tag'] ?? ($tagByLegacyNest[$values['nest_id'] ?? ''] ?? '');

        $eggList = [];
        $query = $selectedTag !== '' ? ['filter' => ['tag' => $selectedTag]] : [];
        foreach ($this->all('/api/application/eggs', $query) as $egg) {
            $eggList[$egg['attributes']['id']] = $egg['attributes']['name'];
        }

        // Keep the saved egg visible even when the filter would hide it.
        $savedEgg = $values['egg_id'] ?? null;
        if ($savedEgg && !isset($eggList[$savedEgg])) {
            try {
                $egg = $this->request('/api/application/eggs/' . $savedEgg);
                $eggList[$savedEgg] = $egg['attributes']['name'];
            } catch (Exception $e) {
                $eggList[$savedEgg] = 'Egg #' . $savedEgg . ' (not found on the panel)';
            }
        }

        return [
            [
                'name' => 'egg_tag',
                'label' => 'Egg tag',
                'type' => 'select',
                'options' => $tagList,
                'default' => $selectedTag,
                'description' => 'Pterodactyl 2.0 groups eggs with tags instead of nests. Pick a tag to narrow down the egg list, or leave it empty to list every egg.',
                'live' => true,
            ],
            [
                'name' => 'egg_id',
                'label' => 'Egg',
                'type' => 'select',
                'options' => $eggList,
                'required' => true,
            ],
            [
                'name' => 'deploy_tags',
                'label' => 'Node reservation tags',
                'type' => 'select',
                'options' => $tagOptions,
                'multiple' => true,
                'database_type' => 'array',
                'required' => false,
                'description' => 'Only needed if some nodes are reserved for tags in Pterodactyl 2.0. Automatic deployment may then also use nodes reserved for these tags.',
            ],
        ];
    }

    public function createServer(Service $service, $settings, $properties)
    {
        if ($this->getServer($service->id, failIfNotFound: false)) {
            throw new Exception('Server already exists');
        }
        // Smash the properties into the settings
        $settings = array_merge($settings, $properties);

        $eggData = $this->eggData($settings);
        $environment = [];
        foreach ($eggData['attributes']['relationships']['variables']['data'] as $variable) {
            $environment[$variable['attributes']['env_variable']] = $settings[$variable['attributes']['env_variable']] ?? $variable['attributes']['default_value'];
        }

        $orderUser = $service->user;
        // Get the user id if one already exists...
        $user = $this->request('/api/application/users', 'get', ['filter' => ['email' => $orderUser->email]])['data'][0]['attributes']['id'] ?? null;

        // Otherwise create a new user
        if (!$user) {
            $user = $this->request('/api/application/users', 'post', [
                'email' => $orderUser->email,
                'username' => (preg_replace('/[^a-zA-Z0-9]/', '', strtolower(Str::transliterate($orderUser->name))) ?: Str::random(8)) . '_' . Str::random(4),
                'first_name' => $orderUser->first_name ?? '',
                'last_name' => $orderUser->last_name ?? '',
            ])['attributes']['id'];
        }

        if (isset($settings['location'])) {
            $settings['location_ids'] = [$settings['location']];
        }

        $deploymentData = $this->generateDeploymentData($settings, $environment);

        $serverCreationData = [
            'external_id' => (string) $service->id,
            'name' => isset($settings['servername']) ? $settings['servername'] : $service->product->name . ' #' . $service->id,
            'user' => (int) $user,
            'egg' => (int) $settings['egg_id'],
            'docker_image' => isset($settings['docker_image']) ? $settings['docker_image'] : $eggData['attributes']['docker_image'],
            'startup' => $eggData['attributes']['startup'],
            'environment' => $deploymentData['environment'],
            'skip_scripts' => (bool) ($settings['skip_scripts'] ?? false),
            'oom_disabled' => !($settings['oom_killer'] ?? false),
            'limits' => [
                'memory' => (int) $settings['memory'],
                'swap' => (int) $settings['swap'],
                'disk' => (int) $settings['disk'],
                'io' => (int) $settings['io'],
                'threads' => !empty($settings['cpu_pinning']) ? $settings['cpu_pinning'] : null,
                'cpu' => (int) $settings['cpu'],
            ],
            'feature_limits' => [
                'databases' => (int) $settings['databases'],
                'allocations' => $deploymentData['allocations_needed'] + (int) $settings['additional_allocations'],
                'backups' => (int) $settings['backups'],
            ],
            'start_on_completion' => (bool) ($settings['start_on_completion'] ?? false),
        ];
        if ($deploymentData['auto_deploy']) {
            $serverCreationData['deploy'] = [
                // 2.0 validates a list of integers; array_values also keeps 1.x happy
                'locations' => array_values(array_map('intval', (array) ($settings['location_ids'] ?? []))),
                'dedicated_ip' => (bool) ($settings['dedicated_ip'] ?? false),
                'port_range' => array_values((array) ($settings['port_range'] ?? [])),
            ];
            $deployTags = $this->arraySetting($settings['deploy_tags'] ?? []);
            if ($deployTags && $this->generation() === 2) {
                $serverCreationData['deploy']['tags'] = $deployTags;
            }
        } else {
            $serverCreationData['allocation'] = $deploymentData['allocation'];
        }

        $server = $this->request('/api/application/servers', 'post', $serverCreationData);

        return [
            'server' => $server['attributes']['id'],
            'link' => rtrim($this->config('host'), '/') . '/server/' . $server['attributes']['identifier'],
        ];
    }

    private function generateDeploymentData($settings, $environment)
    {
        if (!isset($settings['port_array']) || $settings['port_array'] === '') {
            if (!empty($settings['node'])) {
                // Only get one allocation from the node
                $nodes = $this->request('/api/application/nodes/deployable', 'get', [
                    'memory' => $settings['memory'],
                    'disk' => $settings['disk'],
                    'location_ids' => $settings['location_ids'] ?? [],
                    'include' => ['allocations'],
                ]);
                $nodes = collect($nodes['data']);
                $nodes_by_id = $nodes->mapWithKeys(fn ($node) => [$node['attributes']['id'] => $node['attributes']]);

                if (!$nodes_by_id->has($settings['node'])) {
                    throw new Exception('Node is not suitable for deployment.');
                }
                $node = $nodes_by_id->get($settings['node']);
                $availablePorts = collect($node['relationships']['allocations']['data']);
                $availablePorts = $availablePorts
                    ->filter(fn ($port) => !$port['attributes']['assigned'])
                    ->map(
                        fn ($port) => [
                            'port' => $port['attributes']['port'],
                            'id' => $port['attributes']['id'],
                        ]
                    );
                if ($availablePorts->isEmpty()) {
                    throw new Exception('No available allocations found on the selected node.');
                }
                $allocation = $availablePorts->first();
                $environment['SERVER_PORT'] = $allocation['port'];

                // Return the allocation id for the SERVER_PORT
                return [
                    'auto_deploy' => false,
                    'environment' => $environment,
                    'allocations_needed' => 1,
                    'allocation' => [
                        'default' => $allocation['id'],
                        'additional' => [],
                    ],
                ];
            }

            return [
                'auto_deploy' => true,
                'environment' => $environment,
                'allocations_needed' => 1,
            ];
        }

        try {
            // Example: {"SERVER_PORT": 7777, "NONE": [7778, 7779], "QUERY_PORT": 2701, "RCON_PORT": 27020}
            $port_array = json_decode($settings['port_array'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
        } catch (Exception $e) {
            throw new Exception('Invalid JSON in port array');
        }

        if (!is_array($port_array)) {
            throw new Exception('Port array must be an array');
        }

        $nodes = $this->request('/api/application/nodes/deployable', 'get', [
            'memory' => $settings['memory'],
            'disk' => $settings['disk'],
            'location_ids' => $settings['location_ids'] ?? [],
            'include' => ['allocations'],
        ]);
        $nodes = collect($nodes['data']);
        $nodes_by_id = $nodes->mapWithKeys(fn ($node) => [$node['attributes']['id'] => $node['attributes']]);

        $free_allocations_needed = 0;
        foreach ($port_array as $key => $value) {
            $free_allocations_needed += is_array($value) ? count($value) : 1;
        }

        if (!empty($settings['node'])) {
            // If the product's node id is not in the deployable nodes array, throw error.
            if (!$nodes_by_id->has($settings['node'])) {
                throw new Exception('Node is not suitable for deployment.');
            }

            $node = $nodes_by_id->get($settings['node']);
            $availablePorts = $this->freePorts($node['relationships']['allocations']['data']);

            if (count($availablePorts) < $free_allocations_needed) {
                throw new Exception("Not enough allocations found for deployment. Found: {$availablePorts->count()}, Required: {$free_allocations_needed}");
            }
        } else {
            if ($nodes->isEmpty()) {
                throw new Exception('No nodes with suitable allocations found for deployment');
            }
            foreach ($nodes->values() as $index => $node) {
                $availablePorts = $this->freePorts($node['attributes']['relationships']['allocations']['data']);

                if (count($availablePorts) < $free_allocations_needed) {
                    // If this was last viable node, throw error
                    if ($index == $nodes->count() - 1) {
                        throw new Exception('No nodes with suitable allocations found for deployment');
                    }

                    // Else move onto next viable node
                    continue;
                }
                break;
            }
        }

        $allocations = [];
        foreach ($port_array as $key => $value) {
            foreach ((array) $value as $port) {
                $allocation = $availablePorts->where('port', $port)->first()
                    // try to assign a higher port, if that fails try a random port
                    ?? $availablePorts->where('port', '>', $port)->first()
                    ?? ($availablePorts->isNotEmpty() ? $availablePorts->random() : null);
                if (!$allocation) {
                    throw new Exception('Could not find a port to assign');
                }

                if (is_array($value)) {
                    $allocations[$key][] = $allocation;
                } else {
                    $allocations[$key] = $allocation;
                }

                // Remove the port from the available ports
                $availablePorts = $availablePorts->reject(fn ($free) => $free['id'] == $allocation['id']);
            }
        }

        if (!isset($allocations['SERVER_PORT'])) {
            throw new Exception('The port array needs a SERVER_PORT entry');
        }

        $allocationIds = [];

        foreach ($allocations as $key => $value) {
            // Assign the allocations to the environment
            if ($key !== 'NONE' && isset($environment[$key]) && isset($value['port'])) {
                $environment[$key] = $value['port'];
            }

            // Set allocations to a array with only the ids
            if ($key !== 'SERVER_PORT') {
                if (is_array($value) && isset($value[0])) {
                    foreach ($value as $v) {
                        $allocationIds[] = $v['id'];
                    }
                } else {
                    $allocationIds[] = $value['id'];
                }
            }
        }

        return [
            'auto_deploy' => false,
            'allocations_needed' => $free_allocations_needed,
            'environment' => $environment,
            'allocation' => [
                'default' => $allocations['SERVER_PORT']['id'],
                'additional' => $allocationIds,
            ],
        ];
    }

    private function getServer($id, $failIfNotFound = true, $raw = false)
    {
        try {
            $response = $this->request('/api/application/servers/external/' . $id);
        } catch (Exception $e) {
            if ($failIfNotFound) {
                throw new Exception('Server not found');
            } else {
                return false;
            }
        }
        if ($raw) {
            return $response;
        }

        return $response['attributes']['id'] ?? false;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id);

        $this->request('/api/application/servers/' . $server . '/suspend', 'post');

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id);

        $this->request('/api/application/servers/' . $server . '/unsuspend', 'post');

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id);

        $this->request('/api/application/servers/' . $server, 'delete');

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id, raw: true);

        $settings = array_merge($settings, $properties);

        $updateServerData = [
            'allocation' => $server['attributes']['allocation'],
            'memory' => (int) $settings['memory'],
            'swap' => (int) $settings['swap'],
            'disk' => (int) $settings['disk'],
            'io' => (int) $settings['io'],
            'cpu' => (int) $settings['cpu'],
            'threads' => !empty($settings['cpu_pinning']) ? $settings['cpu_pinning'] : null,
            'feature_limits' => [
                'databases' => (int) $settings['databases'],
                'allocations' => (int) $settings['additional_allocations'],
                'backups' => (int) $settings['backups'],
            ],
        ];

        $this->request('/api/application/servers/' . $server['attributes']['id'] . '/build', 'patch', $updateServerData);

        $eggData = $this->eggData($settings);

        $environment = [];

        foreach ($eggData['attributes']['relationships']['variables']['data'] as $variable) {
            // Check if variable has been set on server
            if (isset($server['attributes']['container']['environment'][$variable['attributes']['env_variable']])) {
                $environment[$variable['attributes']['env_variable']] = $server['attributes']['container']['environment'][$variable['attributes']['env_variable']];
            } else {
                $environment[$variable['attributes']['env_variable']] = $settings[$variable['attributes']['env_variable']] ?? $variable['attributes']['default_value'];
            }
        }

        $updateServerData = [
            'environment' => $environment,
            'skip_scripts' => (bool) ($settings['skip_scripts'] ?? false),
            'oom_disabled' => !($settings['oom_killer'] ?? false),
            'egg' => (int) $settings['egg_id'],
            'image' => $server['attributes']['container']['image'] ?? $eggData['attributes']['docker_image'],
            'startup' => $server['attributes']['container']['startup_command'] ?? $settings['startup'] ?? $eggData['attributes']['startup'],
        ];

        $this->request('/api/application/servers/' . $server['attributes']['id'] . '/startup', 'patch', $updateServerData);

        return true;
    }

    public function getActions(Service $service)
    {
        $server = $this->getServer($service->id, raw: true);
        $link = rtrim($this->config('host'), '/') . '/server/' . $server['attributes']['identifier'];

        $actions = [
            [
                'type' => 'button',
                'label' => 'Go to server',
                'url' => $link,
            ],
        ];

        // The optional Karelia Bridge add-on replaces the plain link with a one-click sign-in.
        $bridge = self::BRIDGE_CLASS;
        if (class_exists($bridge) && method_exists($bridge, 'serverActions')) {
            try {
                $actions = $bridge::serverActions($service, $server['attributes'], $actions, rtrim($this->config('host'), '/'));
            } catch (Exception $e) {
                report($e);
            }
        }

        return $actions;
    }

    /**
     * Egg with its variables. 2.0 has /eggs/{id}; 1.x needs the nest, which older products always have.
     */
    private function eggData(array $settings): array
    {
        if (empty($settings['egg_id'])) {
            throw new Exception('The product has no egg selected');
        }

        if ($this->generation() === 2) {
            $path = '/api/application/eggs/' . $settings['egg_id'];
        } else {
            $nest = $settings['nest_id'] ?? $this->findNestForEgg((int) $settings['egg_id']);
            $path = '/api/application/nests/' . $nest . '/eggs/' . $settings['egg_id'];
        }

        $eggData = $this->request($path, 'get', ['include' => 'variables']);
        if (!isset($eggData['attributes'])) {
            throw new Exception('Could not fetch egg data');
        }

        return $eggData;
    }

    /**
     * A product saved on 2.0 has no nest. If the panel is downgraded to 1.x, look the nest up once.
     */
    private function findNestForEgg(int $eggId): int
    {
        foreach ($this->all('/api/application/nests') as $nest) {
            foreach ($this->all('/api/application/nests/' . $nest['attributes']['id'] . '/eggs') as $egg) {
                if ((int) $egg['attributes']['id'] === $eggId) {
                    return (int) $nest['attributes']['id'];
                }
            }
        }

        throw new Exception('Egg #' . $eggId . ' was not found in any nest');
    }

    /**
     * Every item of a paginated list endpoint (the panel returns at most 100 per page).
     */
    private function all(string $path, array $query = []): array
    {
        $items = [];
        $page = 1;
        do {
            $response = $this->request($path, 'get', array_merge($query, ['per_page' => 100, 'page' => $page]));
            $items = array_merge($items, $response['data'] ?? []);
            $totalPages = (int) ($response['meta']['pagination']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 50);

        return $items;
    }

    private function freePorts(array $allocations)
    {
        return collect($allocations)
            ->filter(fn ($port) => !$port['attributes']['assigned'])
            ->map(fn ($port) => [
                'port' => $port['attributes']['port'],
                'id' => $port['attributes']['id'],
            ])
            ->values();
    }

    private function arraySetting($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ($value === '' ? [] : [$value]);
        }

        return array_values(array_filter((array) $value, fn ($item) => $item !== null && $item !== ''));
    }

    private function send(string $url, string $method = 'get', array $data = []): Response
    {
        $method = strtolower($method);

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config('api_key'),
            'Accept' => 'application/json',
        ])->timeout(30)->$method(rtrim((string) $this->config('host'), '/') . $url, $data);
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();
        $detail = is_array($json) ? ($json['errors'][0]['detail'] ?? $json['message'] ?? null) : null;

        return 'Pterodactyl answered HTTP ' . $response->status() . ($detail ? ': ' . $detail : '');
    }

    private function generationCacheKey(): string
    {
        return 'pterodactyl2.generation.' . md5(rtrim((string) $this->config('host'), '/'));
    }
}
