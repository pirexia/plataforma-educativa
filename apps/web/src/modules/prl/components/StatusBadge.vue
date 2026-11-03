<script setup lang="ts">
/**
 * `REQ-FAM-UNIT/funcional.md §23.7` (WCAG 1.4.1): «Abierta»/«Cerrada» **nunca se transmiten solo por color**: siempre
 * llevan el texto y un icono distinto por estado; el color solo refuerza (variante de la insignia).
 */
import type { Component } from 'vue'
import { Badge } from '@/components/ui/badge'

defineProps<{
  text: string
  icon: Component
  variant?: 'default' | 'secondary' | 'destructive' | 'outline'
}>()
</script>

<template>
  <Badge :variant="variant ?? 'outline'" data-slot="prl-status">
    <component :is="icon" aria-hidden="true" />
    {{ text }}
  </Badge>
</template>
