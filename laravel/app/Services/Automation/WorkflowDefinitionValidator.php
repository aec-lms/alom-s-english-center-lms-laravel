<?php

declare(strict_types=1);

namespace App\Services\Automation;

use InvalidArgumentException;

final class WorkflowDefinitionValidator
{
    private const NODE_TYPES = ['trigger', 'condition', 'action', 'delay'];
    private const ACTIONS = ['send_message', 'assign_conversation', 'add_tag', 'http_request', 'notify_admin'];

    public static function validate(array $definition, string $triggerEvent): array
    {
        $nodes = $definition['nodes'] ?? null;
        $edges = $definition['edges'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) < 2 || count($nodes) > 100) {
            throw new InvalidArgumentException('A published workflow needs between 2 and 100 nodes.');
        }
        if (!is_array($edges) || !array_is_list($edges) || count($edges) > 250) {
            throw new InvalidArgumentException('Workflow edges must be a list with at most 250 connections.');
        }

        $byId = [];
        $triggers = 0;
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['id'], $node['type']) || !is_string($node['id']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $node['id'])) {
                throw new InvalidArgumentException('Every node needs a valid unique id and type.');
            }
            if (isset($byId[$node['id']])) throw new InvalidArgumentException('Workflow node ids must be unique.');
            if (!in_array($node['type'], self::NODE_TYPES, true)) throw new InvalidArgumentException('Unsupported workflow node type.');
            $config = $node['config'] ?? [];
            if (!is_array($config)) throw new InvalidArgumentException('Node config must be an object.');
            if ($node['type'] === 'trigger') {
                $triggers++;
                if (($config['event'] ?? null) !== $triggerEvent) throw new InvalidArgumentException('The trigger node must match the workflow trigger event.');
            }
            if ($node['type'] === 'action' && !in_array($config['action'] ?? null, self::ACTIONS, true)) {
                throw new InvalidArgumentException('Unsupported workflow action.');
            }
            if ($node['type'] === 'delay' && (!isset($config['seconds']) || !is_int($config['seconds']) || $config['seconds'] < 1 || $config['seconds'] > 2592000)) {
                throw new InvalidArgumentException('Delay must be between 1 and 2592000 seconds.');
            }
            $byId[$node['id']] = $node;
        }
        if ($triggers !== 1) throw new InvalidArgumentException('A workflow must contain exactly one trigger node.');

        $adjacency = array_fill_keys(array_keys($byId), []);
        $indegree = array_fill_keys(array_keys($byId), 0);
        foreach ($edges as $edge) {
            if (!is_array($edge) || !isset($edge['source'], $edge['target']) || !is_string($edge['source']) || !is_string($edge['target'])
                || !isset($byId[$edge['source']], $byId[$edge['target']]) || $edge['source'] === $edge['target']) {
                throw new InvalidArgumentException('Every connection must link two different existing nodes.');
            }
            $adjacency[$edge['source']][] = $edge['target'];
            $indegree[$edge['target']]++;
        }
        $queue = [];
        foreach ($indegree as $id => $degree) if ($degree === 0) $queue[] = $id;
        $visited = 0;
        while ($queue !== []) {
            $id = array_shift($queue);
            $visited++;
            foreach ($adjacency[$id] as $next) if (--$indegree[$next] === 0) $queue[] = $next;
        }
        if ($visited !== count($byId)) throw new InvalidArgumentException('Workflow connections cannot contain cycles.');
        $definition['version'] = 1;
        return $definition;
    }
}
