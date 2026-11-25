<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Automation\WorkflowDefinitionValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WorkflowDefinitionValidatorTest extends TestCase
{
    private function graph(array $edges = [['source' => 'start', 'target' => 'send']]): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'trigger', 'config' => ['event' => 'contact.created']],
                ['id' => 'send', 'type' => 'action', 'config' => ['action' => 'notify_admin']],
            ],
            'edges' => $edges,
        ];
    }

    public function test_accepts_a_valid_trigger_to_action_graph(): void
    {
        $result = WorkflowDefinitionValidator::validate($this->graph(), 'contact.created');
        self::assertSame(1, $result['version']);
        self::assertCount(2, $result['nodes']);
    }

    public function test_rejects_a_trigger_that_does_not_match_the_workflow_event(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkflowDefinitionValidator::validate($this->graph(), 'order.created');
    }

    public function test_rejects_cycles_in_workflow_graphs(): void
    {
        $graph = $this->graph([
            ['source' => 'start', 'target' => 'send'],
            ['source' => 'send', 'target' => 'start'],
        ]);
        $this->expectException(InvalidArgumentException::class);
        WorkflowDefinitionValidator::validate($graph, 'contact.created');
    }

    public function test_rejects_unknown_actions_before_publishing(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['config']['action'] = 'execute_arbitrary_code';
        $this->expectException(InvalidArgumentException::class);
        WorkflowDefinitionValidator::validate($graph, 'contact.created');
    }

    public function test_rejects_edges_that_reference_missing_nodes(): void
    {
        $graph = $this->graph([['source' => 'start', 'target' => 'missing']]);
        $this->expectException(InvalidArgumentException::class);
        WorkflowDefinitionValidator::validate($graph, 'contact.created');
    }
}
