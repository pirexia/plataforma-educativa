<?php

namespace App\Modules\Acad\Application;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/funcional.md §18.4.4 (`RN-ACAD-112`, `RN-ACAD-113`), `INV-010`:
 * validación de negocio del conjunto completo de franjas de un marco. Cada
 * error se añade a `ValidationErrorBag` en `slots.<n>.<campo>` (api.md §12.5).
 * Horas `HH:MM` (24 h, hora civil del centro, `ADR-065`) comparadas como
 * cadenas con relleno de ceros; intervalos semiabiertos `[inicio, fin)`.
 */
final class TimeSlotsValidator
{
    /** `RN-ACAD-113` [DERIVADA]: tope de franjas por marco, precedente `RN-ACAD-89`. */
    public const MAX_SLOTS = 100;

    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param  list<string>  $storedPublicIds  `public_id` de las franjas vivas del marco (vacío en el alta)
     * @return list<array{public_id: ?string, weekday: int, starts_at: string, ends_at: string}>|null `null` si hay errores
     */
    public function resolve(mixed $slots, array $storedPublicIds, ValidationErrorBag $errors): ?array
    {
        if (! is_array($slots) || ! array_is_list($slots)) {
            $errors->add('slots', 'acad.validation.slots_invalid', 'acad.validation.slots_invalid');

            return null;
        }

        if (count($slots) > self::MAX_SLOTS) {
            $errors->add('slots', 'acad.validation.slots_too_many', 'acad.validation.slots_too_many', ['max' => self::MAX_SLOTS]);

            return null;
        }

        $resolved = [];
        $seen = [];
        $failed = false;

        foreach ($slots as $n => $slot) {
            if (! is_array($slot)) {
                $errors->add("slots.{$n}", 'acad.validation.slots_invalid', 'acad.validation.slots_invalid');
                $failed = true;

                continue;
            }

            $weekday = $slot['weekday'] ?? null;
            $weekdayOk = is_int($weekday) && $weekday >= 1 && $weekday <= 7;

            if (! $weekdayOk) {
                $errors->add("slots.{$n}.weekday", 'acad.validation.weekday_invalid', 'acad.validation.weekday_invalid');
            }

            $startsAt = $this->time($slot['starts_at'] ?? null, "slots.{$n}.starts_at", $errors);
            $endsAt = $this->time($slot['ends_at'] ?? null, "slots.{$n}.ends_at", $errors);
            $timesOk = $startsAt !== null && $endsAt !== null;

            if ($timesOk && $endsAt <= $startsAt) {
                $errors->add("slots.{$n}.ends_at", 'acad.validation.slot_ends_before_start', 'acad.validation.slot_ends_before_start');
                $timesOk = false;
            }

            $publicId = null;

            if (array_key_exists('public_id', $slot) && $slot['public_id'] !== null) {
                $candidate = $slot['public_id'];

                if (! is_string($candidate) || ! in_array($candidate, $storedPublicIds, true) || isset($seen[$candidate])) {
                    $errors->add("slots.{$n}.public_id", 'acad.validation.slot_not_in_frame', 'acad.validation.slot_not_in_frame');
                    $failed = true;
                } else {
                    $seen[$candidate] = true;
                    $publicId = $candidate;
                }
            }

            if (! $weekdayOk || ! $timesOk) {
                $failed = true;

                continue;
            }

            $resolved[$n] = ['public_id' => $publicId, 'weekday' => $weekday, 'starts_at' => (string) $startsAt, 'ends_at' => (string) $endsAt];
        }

        $this->assertNoOverlap($resolved, $errors);

        return $failed || ! $errors->isEmpty() ? null : array_values($resolved);
    }

    /**
     * `RN-ACAD-112`: sin solape entre franjas del mismo día; `[inicio, fin)`, así que 08:30-09:25 y 09:25-10:20 no se solapan.
     *
     * @param  array<int, array{public_id: ?string, weekday: int, starts_at: string, ends_at: string}>  $resolved  por posición en la lista
     */
    private function assertNoOverlap(array $resolved, ValidationErrorBag $errors): void
    {
        $byDay = [];

        foreach ($resolved as $n => $slot) {
            $byDay[$slot['weekday']][] = [$n, $slot];
        }

        foreach ($byDay as $entries) {
            usort($entries, static fn (array $a, array $b): int => [$a[1]['starts_at'], $a[0]] <=> [$b[1]['starts_at'], $b[0]]);

            for ($i = 1, $count = count($entries); $i < $count; $i++) {
                for ($j = 0; $j < $i; $j++) {
                    if ($entries[$j][1]['ends_at'] > $entries[$i][1]['starts_at']) {
                        $errors->add("slots.{$entries[$i][0]}.starts_at", 'acad.validation.slot_overlap', 'acad.validation.slot_overlap', ['slot' => $entries[$j][0]]);

                        break;
                    }
                }
            }
        }
    }

    private function time(mixed $value, string $field, ValidationErrorBag $errors): ?string
    {
        if (! is_string($value) || preg_match(self::TIME_PATTERN, $value) !== 1) {
            $errors->add($field, 'acad.validation.time_invalid', 'acad.validation.time_invalid');

            return null;
        }

        return $value;
    }
}
