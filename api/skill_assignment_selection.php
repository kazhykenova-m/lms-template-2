<?php
/** Practical tasks belong to the employee's department, role and selected level. */
function skillRoleAssignments(array $tests, int $deptId, int $roleId): array {
    if ($deptId <= 0 || $roleId <= 0) return [];
    return array_values(array_filter($tests, fn($test) =>
        ($test['content_type'] ?? '') === 'task'
        && (int)($test['dept_id'] ?? 0) === $deptId
        && (int)($test['role_id'] ?? 0) === $roleId
    ));
}
function skillRandomAssignmentIds(array $assignments): array {
    if (!$assignments) return [];
    return [(int)$assignments[random_int(0, count($assignments)-1)]['id']];
}
