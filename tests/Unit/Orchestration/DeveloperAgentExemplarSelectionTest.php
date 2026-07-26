<?php

namespace Tests\Unit\Orchestration;

use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineTaskStatus;
use App\Modules\Orchestration\Models\PipelineTask;
use App\Modules\Orchestration\Services\Agents\DeveloperAgent;
use ReflectionMethod;
use Tests\TestCase;

class DeveloperAgentExemplarSelectionTest extends TestCase
{
    public function test_selects_peer_forms_over_readme(): void
    {
        $agent = app(DeveloperAgent::class);
        $method = new ReflectionMethod(DeveloperAgent::class, 'selectExemplarPaths');
        $method->setAccessible(true);

        $task = new PipelineTask([
            'title' => 'Implement student feedback form',
            'description' => 'Add StudentFeedbackForm for co-op feedback submission',
            'agent_role' => AgentRole::Developer,
            'status' => PipelineTaskStatus::Pending,
            'files_hint' => ['src/main/java/com/rit/ces/feedback/EmployerFeedbackForm.java'],
        ]);

        $paths = [
            'README.md',
            'pom.xml',
            'src/main/java/com/rit/ces/feedback/EmployerFeedbackForm.java',
            'src/main/java/com/rit/ces/feedback/FeedbackController.java',
            'src/main/java/com/rit/ces/auth/LoginDto.java',
            'docs/overview.md',
            'package-lock.json',
        ];

        /** @var list<string> $selected */
        $selected = $method->invoke($agent, $paths, $task->files_hint ?? [], $task);

        $this->assertContains('src/main/java/com/rit/ces/feedback/EmployerFeedbackForm.java', $selected);
        $this->assertContains('src/main/java/com/rit/ces/feedback/FeedbackController.java', $selected);
        $this->assertNotContains('README.md', $selected);
        $this->assertNotContains('package-lock.json', $selected);
    }

    public function test_infer_stack_notes_prefers_jakarta_and_lombok_from_snippets(): void
    {
        $agent = app(DeveloperAgent::class);
        $method = new ReflectionMethod(DeveloperAgent::class, 'inferStackNotes');
        $method->setAccessible(true);

        $notes = $method->invoke(
            $agent,
            ['pom.xml', 'src/main/java/com/rit/ces/feedback/EmployerFeedbackForm.java'],
            [
                'src/main/java/com/rit/ces/feedback/EmployerFeedbackForm.java' => <<<'JAVA'
package com.rit.ces.feedback;

import jakarta.validation.constraints.NotBlank;
import lombok.Data;

@Data
public class EmployerFeedbackForm {
    @NotBlank
    private String employerId;
}
JAVA,
            ],
        );

        $this->assertStringContainsString('jakarta', strtolower($notes));
        $this->assertStringContainsString('lombok', strtolower($notes));
        $this->assertStringNotContainsString('exemplars still use javax', strtolower($notes));
    }
}
