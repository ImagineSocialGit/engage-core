# Configuration TODO

Durable contracts live in `config-contracts.md`, authoring guidance in `config-authoring-guide.md`, and ordered implementation direction in `roadmap.md`.

- [ ] Run registered closed config contracts from `setup:validate` so structural rules are not duplicated across tests and contributor code.
- [ ] Add closed contracts for complete file envelopes, reference keys, conditional objects, and producer-owned Campaign start payloads.
- [ ] Add strict reference and token closure validation for every exported definition.
- [ ] Generate field/token tables and contract-derived authoring references in CI.
- [ ] Build a minimal deterministic exporter and semantic round trip using a representative full-package fixture through the shared contracts, setup-validation path, and representative runtime coverage.
- [ ] Build preview/authoring UX as consumers of the same registries and strict validator.
- [ ] Verify the production preset/module setup-validation false positive remains resolved under production-shaped config.
- [ ] Remove the hand-maintained full module-registry copy from `docs/config-templates/modules-template.php`; document shape without duplicating every registered module/provider/dependency.
