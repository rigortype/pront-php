.PHONY: help setup test docs-check docs-check-selftest whitespace verify-changed

help:
	@echo "Run every target through: nix develop --command make <target>"
	@echo "  setup           composer install"
	@echo "  test            PHPUnit (needs make setup)"
	@echo "  docs-check      document-role and link gates (ADR-0001)"
	@echo "  whitespace      whitespace errors in everything this branch changed, committed or not"
	@echo "  docs-check-selftest  fixtures that prove docs-check fails when it should"
	@echo "  verify-changed  whitespace + docs-check + selftest + test when installed (grows with the code)"

setup:
	composer install

test:
	vendor/bin/phpunit

docs-check:
	php bin/docs-check

docs-check-selftest:
	php bin/docs-check-selftest

# Covers everything from the merge-base with origin/master (or the whole tree when there is no
# origin/master yet) to the working tree, including untracked, non-ignored files. A temporary index
# stands in for "add -A" so the real index is untouched.
whitespace:
	@base=$$(git merge-base HEAD origin/master 2>/dev/null || git hash-object -t tree /dev/null); \
	idx=$$(mktemp -u); \
	GIT_INDEX_FILE=$$idx git read-tree HEAD && GIT_INDEX_FILE=$$idx git add -A && \
	  GIT_INDEX_FILE=$$idx git diff --cached --check "$$base"; \
	rc=$$?; rm -f "$$idx"; exit $$rc

# The tests run when `make setup` has installed PHPUnit; otherwise the skip is reported, not silent.
# REQUIRE_TESTS=1 (the merge gate, docs/agents/contribution-flow.md) turns the skip into a failure.
verify-changed: whitespace docs-check docs-check-selftest
	@if [ -x vendor/bin/phpunit ]; then \
	  $(MAKE) --no-print-directory test; \
	elif [ -n "$(REQUIRE_TESTS)" ]; then \
	  echo "verify-changed: tests NOT run and REQUIRE_TESTS is set (vendor/bin/phpunit missing; run make setup)" >&2; \
	  exit 1; \
	else \
	  echo "verify-changed: tests NOT run (vendor/bin/phpunit missing; run make setup)" >&2; \
	fi
