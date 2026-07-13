<?php

namespace Icinga\Module\Proactiveha\Common;

use Icinga\Authentication\Auth;
use ipl\Stdlib\Filter;

trait RestrictionFilter
{
    protected function applyRestrictions($query, $relation = null)
    {
        $user = Auth::getInstance()->getUser();
        if ($user->isUnrestricted()) {
            return;
        }

        $any = Filter::any();

        foreach ($user->getRoles() as $role) {
            $restriction = $role->getRestrictions('proactiveha/filter');
            if (empty($restriction)) {
                continue;
            }

            if (is_array($restriction)) {
                $restriction = implode(',', $restriction);
            }

            foreach (array_filter(array_map('trim', explode(',', $restriction))) as $value) {
                $column = $relation ? "$relation.name" : 'name';
                $any->add(Filter::like($column, $value));
            }
        }

        if (!$any->isEmpty()) {
            $query->filter($any);
        }
    }
}
