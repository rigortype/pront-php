# Architecture Decision Records

Architecture, process, product and scope decisions. ADR-0008 is the north star (concept and
principles only).

ADR-0008..0015 were the PDR-0..7 series until 2026-11-08 (PDR-N = ADR-(N+8)); there is one decision
record series because separate product and architecture series were never intended. Old references to
"PDR-N" in commit messages mean ADR-(N+8).
Every ADR file must appear in this index (`make docs-check`).

| ADR | Status | Subject |
| --- | --- | --- |
| [ADR-0001](0001-development-flow-document-roles.md) | Proposed | Development-flow document roles |
| [ADR-0002](0002-choice-buffer-ordering-and-shrinker.md) | Accepted | Choice-buffer ordering and shrinker |
| [ADR-0003](0003-type-resolver-stage-1.md) | Accepted | Type resolver (Stage 1) |
| [ADR-0004](0004-execution-model-stage-1.md) | Accepted | Execution model (Stage 1) |
| [ADR-0005](0005-runtime-dependencies.md) | Accepted | Runtime dependencies and versions |
| [ADR-0006](0006-attribute-packaging.md) | Accepted | Attribute packaging |
| [ADR-0007](0007-edge-values-and-defaults.md) | Accepted | Edge values and provisional defaults |
| [ADR-0008](0008-concept.md) | Proposed | Project concept (north star) |
| [ADR-0009](0009-declarative-surface.md) | Proposed | Declarative surface |
| [ADR-0010](0010-generation.md) | Proposed | Generation |
| [ADR-0011](0011-reproducibility-and-isolation.md) | Proposed | Reproducibility and execution isolation |
| [ADR-0012](0012-contract-inheritance.md) | Proposed | Contract inheritance |
| [ADR-0013](0013-tooling.md) | Proposed | Tooling |
| [ADR-0014](0014-runtime-and-releases.md) | Proposed | Runtime, packaging, versioning and release stages |
| [ADR-0015](0015-sketch-reconciliation.md) | Proposed | Reconciliation with the original sketch |

## ADRs still to write

Each becomes an issue before it becomes an ADR: type-vocabulary extension mechanism (Stage 2);
worker-process isolation, `--isolate` (later, unscheduled); PHPUnit bridge (later, unscheduled); equality comparators and
the `$old` snapshot; dependent-constraint Attribute syntax; `Immutable` audit; CoArbitrary design;
stateful runner design. Re-tuning of the provisional defaults of ADR-0007 follows measurements on
real code.

Stability criteria for v0.1.0 are a product decision (a future ADR, see ADR-0014).
