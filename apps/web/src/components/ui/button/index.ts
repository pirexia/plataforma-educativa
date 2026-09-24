import type { VariantProps } from 'class-variance-authority'
import { cva } from 'class-variance-authority'

export { default as Button } from './Button.vue'

export const buttonVariants = cva(
  'focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:aria-invalid:border-destructive/50 rounded-lg border border-transparent bg-clip-padding text-sm font-medium focus-visible:ring-3 aria-invalid:ring-3 active:not-aria-[haspopup]:translate-y-px [&_svg:not([class*=size-])]:size-4 group/button inline-flex shrink-0 items-center justify-center whitespace-nowrap transition-all outline-none select-none disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        // `RN-DS-17`/`RN-DS-18`, docs/design-system.md §12.1: sin
        // `hover:bg-primary/80` (contraste no garantizado con opacidad) y
        // `border-primary-on-background` en vez de `border-transparent` —
        // mitiga que, con un primario muy oscuro en modo oscuro (o muy
        // claro en claro), el botón pierda contorno visible contra el
        // fondo (`ADR-052 §Consecuencias`).
        default:
          'bg-primary text-primary-foreground border-primary-on-background hover:brightness-95 dark:hover:brightness-110',
        outline:
          'border-border bg-background hover:bg-muted hover:text-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 aria-expanded:bg-muted aria-expanded:text-foreground',
        secondary:
          'bg-secondary text-secondary-foreground hover:bg-secondary/80 aria-expanded:bg-secondary aria-expanded:text-secondary-foreground',
        ghost:
          'hover:bg-muted hover:text-foreground dark:hover:bg-muted/50 aria-expanded:bg-muted aria-expanded:text-foreground',
        destructive:
          'bg-destructive/10 hover:bg-destructive/20 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/20 text-destructive focus-visible:border-destructive/40 dark:hover:bg-destructive/30',
        link: 'text-primary-on-background underline-offset-4 hover:underline',
      },
      // `docs/design-system.md §12.1`/`OPEN-CORE-14` (opción B): en
      // punteros gruesos, la altura mínima sube a 44px sin cambiar el
      // escritorio con ratón — `RUX-RESP-007` leído en su sentido
      // literal ("táctiles"), `CA-CORE-084`.
      size: {
        default:
          'h-8 gap-1.5 px-2.5 has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2 [@media(any-pointer:coarse)]:min-h-11',
        xs: 'h-6 gap-1 rounded-[min(var(--radius-md),10px)] px-2 text-xs in-data-[slot=button-group]:rounded-lg has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 [&_svg:not([class*=size-])]:size-3 [@media(any-pointer:coarse)]:min-h-11',
        sm: 'h-7 gap-1 rounded-[min(var(--radius-md),12px)] px-2.5 text-[0.8rem] in-data-[slot=button-group]:rounded-lg has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 [&_svg:not([class*=size-])]:size-3.5 [@media(any-pointer:coarse)]:min-h-11',
        lg: 'h-9 gap-1.5 px-2.5 has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2 [@media(any-pointer:coarse)]:min-h-11',
        icon: 'size-8 [@media(any-pointer:coarse)]:min-h-11 [@media(any-pointer:coarse)]:min-w-11',
        'icon-xs':
          'size-6 rounded-[min(var(--radius-md),10px)] in-data-[slot=button-group]:rounded-lg [&_svg:not([class*=size-])]:size-3 [@media(any-pointer:coarse)]:min-h-11 [@media(any-pointer:coarse)]:min-w-11',
        'icon-sm':
          'size-7 rounded-[min(var(--radius-md),12px)] in-data-[slot=button-group]:rounded-lg [@media(any-pointer:coarse)]:min-h-11 [@media(any-pointer:coarse)]:min-w-11',
        'icon-lg':
          'size-9 [@media(any-pointer:coarse)]:min-h-11 [@media(any-pointer:coarse)]:min-w-11',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
