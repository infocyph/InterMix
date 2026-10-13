.. _release-11-2:

=============================
InterMix 11.2 release notes
=============================

Native compiled scoped inputs
-----------------------------

Declared inputs compile as native scoped entries, allowing fully supported
graphs to load without reconstructing a builder or attaching a dynamic fallback.
Both source-free compiled loading and trusted prevalidated loading support them.

Hosts supply execution values through structured scope seeds. Missing inputs,
undeclared seeds and access outside a scope fail closed. Sequential and concurrent
executions remain isolated, and singleton construction cannot capture inputs.

Recompile deployment artifacts to obtain the native input plans and compiler
corrections below. The generated methods use the existing production scope/slot
infrastructure. The auto-binding runtime contract advances the artifact ABI to 3.
Artifacts with incompatible ABIs,
PHP versions, graph identities or manifests remain rejected. Runtime factories
and unsupported hooks retain their existing fallback and matching release
identity protections.

Opt-in automatic parameter binding
----------------------------------

``ContainerBuilder::enableAutoBind()`` enables class-defined conversion of named
bindings and explicit arguments for single-class typed parameters. A public
static ``fromBoundValue`` method accepts one value by value and must return an
instance compatible with the parameter class. Compatible objects pass through;
null passes through for nullable parameters. Classes without the method keep
their existing injection behavior. Union, intersection and variadic parameters
retain their ordinary resolution rules.

Conversion runs when the parameter is resolved, never while compiling. Native
plans resolve the original service slot before converting its value, preserving
scope ownership and singleton capture protection. Fully supported graphs still
load without a builder or a dynamic fallback. Conversion failures propagate;
InterMix does not infer database queries or HTTP response policy. Late runtime
invokers also retain the artifact's frozen injection and attribute options.

Compatible primitive bindings now resolve by parameter name, including zero,
false and nullable null values. Automatic class conversion remains disabled by
default and can be switched off with ``enableAutoBind(false)`` before freezing
configuration.

Compiler and scope consistency
------------------------------

Compound dependencies now recognize explicit autowire declarations and contextual
service references. Unknown factory result types and non-exportable constructor
defaults retain their protected dynamic fallback rather than selecting a different
dependency or substituting null. Numeric string service IDs survive manifest
loading and scope seeding.

Singleton capture protection applies through nested invocation, fresh construction
and compiled-to-dynamic bridges. Compiled scope values remain owned by the native
scope; reflection bridges resolve them through that scope rather than copying
them into a second seed store. Aliases cannot own scope seeds. Reflection scopes
created during execution synchronize existing concurrent carriers before use.

All loaders verify the manifest's immutable build identity as well as their
existing compatibility and deployment checks. Validly shaped manifest changes
are rejected before the runtime is loaded.

Declared dependencies resolve through the runtime's guarded lookup, avoiding
redundant scope-seed lookups. Fiber carrier identities remain weakly held and
resolve directly through the existing identity map.

Optional Runwire integration
----------------------------

The optional integration now targets Runwire 2.1.1 or later in the 2.x series.
Registered runtime, request and coroutine inputs compile natively and support
source-free loading. The host retains runtime and execution lifecycle ownership.
