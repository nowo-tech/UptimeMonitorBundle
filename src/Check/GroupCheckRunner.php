<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

use function count;
use function sprintf;

/**
 * Heartbeat for project groups: rolls up the latest status of child monitors.
 */
final class GroupCheckRunner implements CheckRunnerInterface
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckResultRepository $checkResultRepository,
    ) {
    }

    public function supports(Monitor $monitor): bool
    {
        return $monitor->getType() === MonitorType::Group;
    }

    public function run(Monitor $monitor): CheckResultDto
    {
        $groupId = $monitor->getId();
        if ($groupId === null) {
            return new CheckResultDto(CheckStatus::Unknown, 0, null, 'Group monitor is not persisted yet.');
        }

        $children = $this->monitorRepository->findChildrenOf($groupId);
        if ($children === []) {
            return new CheckResultDto(
                CheckStatus::Unknown,
                0,
                null,
                'No child monitors in this group.',
            );
        }

        $childIds = array_values(array_filter(array_map(
            static fn (Monitor $m) => $m->getId(),
            $children,
        )));
        $latestByChild = $this->checkResultRepository->findLatestByMonitorIds($childIds);

        $up          = 0;
        $worstStatus = CheckStatus::Up;
        $maxLatency  = 0;

        foreach ($children as $child) {
            $childId = $child->getId();
            $latest  = $childId !== null ? ($latestByChild[$childId] ?? null) : null;
            if ($latest === null) {
                $worstStatus = $this->worstOf($worstStatus, CheckStatus::Unknown);
                continue;
            }

            if ($latest->getStatus() === CheckStatus::Up) {
                ++$up;
            }

            $worstStatus = $this->worstOf($worstStatus, $latest->getStatus());
            $maxLatency  = max($maxLatency, $latest->getLatencyMs());
        }

        $total   = count($children);
        $message = sprintf('%d/%d child monitor(s) up', $up, $total);

        return new CheckResultDto($worstStatus, $maxLatency, null, $message, [
            'children_total' => $total,
            'children_up'    => $up,
        ]);
    }

    private function worstOf(CheckStatus $current, CheckStatus $candidate): CheckStatus
    {
        $rank = [
            CheckStatus::Down->value     => 4,
            CheckStatus::Degraded->value => 3,
            CheckStatus::Unknown->value  => 2,
            CheckStatus::Up->value       => 1,
        ];

        return ($rank[$candidate->value] ?? 0) > ($rank[$current->value] ?? 0)
            ? $candidate
            : $current;
    }
}
