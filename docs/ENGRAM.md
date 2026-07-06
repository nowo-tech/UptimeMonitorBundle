# Engram — AI persistent memory in this repository

Repository-local **product spec** and **`REQ-*`** traceability (Makefiles, demos) are described in [Spec-driven development](SPEC-DRIVEN-DEVELOPMENT.md).

This repository is **prepared to use Engram** with Cursor (and other MCP-compatible editors). The configuration is already present so that once you install the Engram CLI, your AI agent can use persistent memory across sessions.

## Table of contents

- [What is Engram?](#what-is-engram)
- [Repository setup](#repository-setup)
- [How to install Engram](#how-to-install-engram)
- [How to use](#how-to-use)
- [References](#references)

## What is Engram?

**Engram** is an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server that gives AI coding agents persistent memory. It stores context in a local vault (SQLite) so the agent does not need to re-discover project structure and conventions in every session.

## Repository setup

In the **root of this repository** you will find:

- **`.cursor/mcp.json`** — MCP configuration that registers the Engram server with Cursor.

## How to install Engram

```bash
npm install -g engram-sdk
engram init
```

See [Engram documentation](https://www.engram.fyi/docs) for Homebrew and advanced setup.

## How to use

After installation, Cursor can run `engram mcp` via `.cursor/mcp.json`. Use Engram to remember bundle conventions, demo ports, and release checklists across sessions.

## References

- [SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md)
- [BUNDLES_FULL_SPECS_CHECKLIST.md](../BUNDLES_FULL_SPECS_CHECKLIST.md) (monorepo)
