# Engage Core — Deployment Architecture Documentation

## Purpose

This directory contains maintainer-facing deployment architecture and command-ownership contracts.

Operator procedures intentionally live in:

```text
docs/operations/deployment-runbook.md
```

Do not duplicate complete operator deployment sequences here.

## Documents

```text
command-ownership.md
    Defines what each application deployment command owns and does not own.

deployment-orchestrator-contract.md
    Defines launcher/audit/fix lifecycle, topology, naming, state, and repair boundaries.

deployment-plan-and-environment.md
    Defines deployment-plan/environment requirement resolution and ownership.
```

## Boundary

The operator runbook owns:

```text
new environment workflow
normal update workflow
audit
fix
module addition
final deployment gate
links to specialized Project State/provider/troubleshooting procedures
```

These architecture documents own:

```text
command semantics
planner contracts
orchestrator contracts
repair eligibility
environment-requirement semantics
internal lifecycle boundaries
```

When implementation and prose disagree, implementation contracts and executable validation remain authoritative. Update the architecture document and the operator runbook together whenever an operator-visible lifecycle changes.