import type { VariantProps } from 'class-variance-authority'
import { cva } from 'class-variance-authority'

export { default as Badge } from './Badge.vue'

export const badgeVariants = cva(
  // `docs/design-system.md §12.1`/`OPEN-CORE-14` (opción B): en punteros
  // gruesos, cuando se usa como enlace (`[a]:hover:…`), la altura mínima
  // sube a 44px sin cambiar el escritorio con ratón.
  'h-5 gap-1 rounded-4xl border border-transparent px-2 py-0.5 text-xs font-medium transition-all has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 [&>svg]:size-3! group/badge inline-flex w-fit shrink-0 items-center justify-center overflow-hidden whitespace-nowrap focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&>svg]:pointer-events-none [@media(any-pointer:coarse)]:[a]:min-h-11',
  {
    variants: {
      variant: {
        // Mismo criterio que `button` (`docs/design-system.md` §12.1,
        // `RN-DS-17`/`RN-DS-18`): sin `bg-primary/NN`, y
        // `border-primary-on-background` en vez de `border-transparent`.
        default:
          'bg-primary text-primary-foreground border-primary-on-background [a]:hover:brightness-95 dark:[a]:hover:brightness-110',
        secondary: 'bg-secondary text-secondary-foreground [a]:hover:bg-secondary/80',
        destructive:
          'bg-destructive/10 [a]:hover:bg-destructive/20 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 text-destructive dark:bg-destructive/20',
        outline: 'border-border text-foreground [a]:hover:bg-muted [a]:hover:text-muted-foreground',
        ghost: 'hover:bg-muted hover:text-muted-foreground dark:hover:bg-muted/50',
        link: 'text-primary-on-background underline-offset-4 hover:underline',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
