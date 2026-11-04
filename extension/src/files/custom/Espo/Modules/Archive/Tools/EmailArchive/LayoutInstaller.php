<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use Espo\Core\Utils\Json;
use Espo\Tools\LayoutManager\LayoutManager;

/**
 * Adds the "Archived Emails" panel to the detail view of records that can own archived
 * emails. Works on the effective layout, so customizations made by admins are kept; running
 * it again changes nothing.
 */
class LayoutInstaller
{
    private const LINK = 'archivedEmails';
    private const SCOPE_LIST = ['Account', 'Contact', 'Lead', 'Opportunity', 'Case'];

    public function __construct(private LayoutManager $layoutManager) {}

    /**
     * @return string[] Scopes whose layout was changed.
     */
    public function install(): array
    {
        $changed = [];

        foreach (self::SCOPE_LIST as $scope) {
            if ($this->addToBottomPanels($scope) || $this->addToRelationships($scope)) {
                $changed[] = $scope;
            }
        }

        $this->layoutManager->save();

        return $changed;
    }

    /**
     * Removes the panels again (before uninstalling). Other layout changes stay untouched.
     *
     * @return list<array{scope: string, name: string, before: mixed, after: mixed}> The changed
     *     layouts, so a following install can put them back exactly (see restore()).
     */
    public function uninstall(): array
    {
        $changes = [];

        foreach (self::SCOPE_LIST as $scope) {
            $name = 'bottomPanelsDetail';
            $before = $this->decode($this->layoutManager->get($scope, $name));

            if (is_array($before) && !array_is_list($before)) {
                $after = $before;
                unset($after[self::LINK]);
            } else {
                $name = 'relationships';
                $before = $this->decode($this->layoutManager->get($scope, $name));

                if (!is_array($before)) {
                    continue;
                }

                $after = array_values(array_filter($before, fn ($item) => !$this->isLink($item)));
            }

            if ($after === $before) {
                continue;
            }

            $this->layoutManager->set($after, $scope, $name);
            $changes[] = ['scope' => $scope, 'name' => $name, 'before' => $before, 'after' => $after];
        }

        $this->layoutManager->save();

        return $changes;
    }

    /**
     * Puts back layouts changed by uninstall(), unless they were edited since.
     *
     * @param list<array{scope: string, name: string, before: mixed, after: mixed}> $changes
     * @return string[] Scopes whose layout was restored.
     */
    public function restore(array $changes): array
    {
        $restored = [];

        foreach ($changes as $change) {
            $current = $this->decode($this->layoutManager->get($change['scope'], $change['name']));

            if ($current != $change['after']) {
                continue;
            }

            $this->layoutManager->set($change['before'], $change['scope'], $change['name']);
            $restored[] = $change['scope'];
        }

        $this->layoutManager->save();

        return $restored;
    }

    /**
     * Scopes with an explicit bottom panel layout (e.g. Account) list their panels there.
     */
    private function addToBottomPanels(string $scope): bool
    {
        $data = $this->decode($this->layoutManager->get($scope, 'bottomPanelsDetail'));

        if (!is_array($data) || array_is_list($data)) {
            return false;
        }

        if (isset($data[self::LINK])) {
            return false;
        }

        $indexes = array_map(fn ($item) => is_array($item) ? ($item['index'] ?? 0) : 0, $data);

        $data[self::LINK] = ['index' => ($indexes ? max($indexes) : 0) + 1];

        $this->layoutManager->set($data, $scope, 'bottomPanelsDetail');

        return true;
    }

    /**
     * Without a bottom panel layout, the panels come from the relationships layout.
     */
    private function addToRelationships(string $scope): bool
    {
        $bottomPanels = $this->decode($this->layoutManager->get($scope, 'bottomPanelsDetail'));

        if (is_array($bottomPanels) && !array_is_list($bottomPanels)) {
            return false;
        }

        $data = $this->decode($this->layoutManager->get($scope, 'relationships'));

        if (!is_array($data)) {
            $data = [];
        }

        foreach ($data as $item) {
            if ($this->isLink($item)) {
                return false;
            }
        }

        $data[] = self::LINK;

        $this->layoutManager->set($data, $scope, 'relationships');

        return true;
    }

    private function isLink(mixed $item): bool
    {
        return $item === self::LINK || (is_array($item) && ($item['name'] ?? null) === self::LINK);
    }

    private function decode(?string $json): mixed
    {
        return $json === null ? null : Json::decode($json, true);
    }
}
