<?php
declare(strict_types=1);

namespace YangSheep\CRM\Module;

use Closure;

/** File-presence diagnostics only: no DB, environment, module construction or side effects. */
final class ModuleReadinessService
{
    private Closure $exists;

    public function __construct(?Closure $exists = null)
    {
        $this->exists = $exists ?? static fn(string $path): bool => is_file(BASE_PATH . '/' . $path);
    }

    public function report(): array
    {
        $modules = [];
        foreach (ModuleCatalog::definitions() as $definition) {
            $presence = 'planned';
            $reason = 'planned_not_implemented';
            if ($definition['stage'] === 'existing') {
                $found = 0;
                foreach ($definition['sentinels'] as $path) {
                    if (($this->exists)($path)) { $found++; }
                }
                $presence = $found === count($definition['sentinels']) ? 'present'
                    : ($found === 0 ? 'absent' : 'partial');
                $reason = $presence === 'present' ? 'legacy_unmanaged' : 'source_incomplete';
            }
            $modules[] = [
                'id' => $definition['id'], 'label' => $definition['label'],
                'required' => $definition['required'], 'depends_on' => $definition['depends_on'],
                'source_presence' => $presence, 'lifecycle_ready' => false,
                'can_change' => false, 'reason_code' => $reason,
            ];
        }
        return ['catalog_revision' => 1, 'mode' => 'read_only_inventory', 'modules' => $modules];
    }
}
