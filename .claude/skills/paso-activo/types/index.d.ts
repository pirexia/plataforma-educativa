export type Snapshot = { phase: string; current: string; next: string; error: string }

declare module 'claude-code' {
  interface PluginState {
    'paso-activo': { snap: Shaped<Snapshot> }
  }
}
