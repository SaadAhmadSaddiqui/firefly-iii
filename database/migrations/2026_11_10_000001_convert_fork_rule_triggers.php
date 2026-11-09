<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The fork (pre-6.7.9) had custom rule features that upstream Firefly III doesn't know. Rewrite
 * them to upstream's native syntax so rules keep working without carrying custom engine code:
 *
 *  - "<field>_on_day = 12"  → "<field>_on = xxxx-xx-12"   (upstream partial dates)
 *  - "<field>_on_month = 3" → "<field>_on = xxxx-03-xx"
 *  - "<field>_on_day_of_week" has no upstream equivalent: the rule is deactivated and noted.
 *  - multi-value triggers stored as a JSON array (["A","B"], meaning "any of") → the rule is
 *    split into one rule per value, each with the same other triggers and actions.
 *
 * Negated triggers ("-…") keep their negation. Idempotent: converted rows no longer match.
 */
return new class () extends Migration {
    private const array DATE_FIELDS = ['date', 'interest_date', 'book_date', 'process_date', 'due_date', 'payment_date', 'invoice_date', 'created_at', 'updated_at'];

    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('rule_triggers')) {
            return;
        }
        $known = array_keys((array) config('search.operators', []));

        foreach (DB::table('rule_triggers')->get() as $trigger) {
            $negated = str_starts_with($trigger->trigger_type, '-');
            $type    = ltrim($trigger->trigger_type, '-');
            foreach (self::DATE_FIELDS as $field) {
                $value = (int) $trigger->trigger_value;
                if ($type === $field.'_on_day' && $value >= 1 && $value <= 31) {
                    $this->rewrite($trigger, $negated, $field.'_on', sprintf('xxxx-xx-%02d', $value), $known);
                }
                if ($type === $field.'_on_month' && $value >= 1 && $value <= 12) {
                    $this->rewrite($trigger, $negated, $field.'_on', sprintf('xxxx-%02d-xx', $value), $known);
                }
                if ($type === $field.'_on_day_of_week') {
                    $this->disableRule((int) $trigger->rule_id, sprintf('trigger "%s = %s" has no upstream equivalent', $trigger->trigger_type, $trigger->trigger_value));
                }
            }
        }

        // Multi-value triggers: ["A","B","C"] means "any of"; split into one rule per value.
        foreach (DB::table('rule_triggers')->where('trigger_value', 'like', '[%')->get() as $trigger) {
            $values = json_decode((string) $trigger->trigger_value, true);
            if (!is_array($values) || !array_is_list($values) || [] === $values) {
                continue;
            }
            $values = array_values(array_unique(array_map('strval', $values)));
            DB::table('rule_triggers')->where('id', $trigger->id)->update(['trigger_value' => $values[0]]);
            foreach (array_slice($values, 1) as $i => $value) {
                $this->cloneRule((int) $trigger->rule_id, (int) $trigger->id, $value, $i + 2);
            }
            Log::info(sprintf('Fork rule conversion: rule #%d multi-value trigger split into %d rules.', $trigger->rule_id, count($values)));
        }
    }

    public function down(): void
    {
        // Data conversion: the original custom syntax is not restored.
    }

    private function rewrite(object $trigger, bool $negated, string $type, string $value, array $known): void
    {
        if (!in_array($type, $known, true)) {
            $this->disableRule((int) $trigger->rule_id, sprintf('operator "%s" not available', $type));

            return;
        }
        DB::table('rule_triggers')->where('id', $trigger->id)->update(['trigger_type' => ($negated ? '-' : '').$type, 'trigger_value' => $value]);
    }

    private function disableRule(int $ruleId, string $reason): void
    {
        $rule = DB::table('rules')->where('id', $ruleId)->first();
        if (null === $rule || !$rule->active) {
            return;
        }
        DB::table('rules')->where('id', $ruleId)->update([
            'active'      => false,
            'description' => trim(($rule->description ?? '')."\n[Disabled during the v6.7.9 upgrade: {$reason}.]"),
        ]);
        Log::warning(sprintf('Fork rule conversion: rule #%d disabled: %s', $ruleId, $reason));
    }

    private function cloneRule(int $ruleId, int $multiTriggerId, string $value, int $copyNumber): void
    {
        $rule  = (array) DB::table('rules')->where('id', $ruleId)->first();
        $now   = now();
        unset($rule['id']);
        $rule['title']      = sprintf('%s (%d)', $rule['title'], $copyNumber);
        $rule['order']      = (int) DB::table('rules')->where('rule_group_id', $rule['rule_group_id'])->max('order') + 1;
        $rule['created_at'] = $now;
        $rule['updated_at'] = $now;
        $newId              = DB::table('rules')->insertGetId($rule);

        foreach (DB::table('rule_triggers')->where('rule_id', $ruleId)->get() as $trigger) {
            $row = (array) $trigger;
            unset($row['id']);
            $row['rule_id']    = $newId;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            if ((int) $trigger->id === $multiTriggerId) {
                $row['trigger_value'] = $value;
            }
            DB::table('rule_triggers')->insert($row);
        }
        foreach (DB::table('rule_actions')->where('rule_id', $ruleId)->get() as $action) {
            $row = (array) $action;
            unset($row['id']);
            $row['rule_id']    = $newId;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            DB::table('rule_actions')->insert($row);
        }
    }
};
