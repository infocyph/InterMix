.. _di.flow:

=============
Feature flow
=============

In this section you will know what happens under the hood (if **autowiring** is enabled).

Main Process steps
------------------

After setting all the options/registering what ever we need we just execute ``get`` or ``getReturn`` or ``call``.

* ``get`` or ``getReturn`` calls ``call`` under the hood. Only difference is they cache everything.
* If it is definition return the resolved result
* If it is function

    * resolves all the parameter
    * execute & return the result

* If it is class

    * resolve parameters in the constructor
    * resolve properties
    * resolve properties with Attributes (based on set options & if applicable)
    * Select method based on priority (check below)
    * gather method Attribute (based on set options & if applicable)
    * gather parameter Attribute (based on set options & if applicable)
    * resolve method parameters (will also use attributes found in previous step)
    * execute & return the result

Parameter resolution steps
---------------------------

The process goes through every parameter of the in-progress method (this also include ``__constructor()``) and resolves through.

* Get all the supplied parameters for that method (via ``registerMethod()``)
* Get all the parameter assigned via attribute (if ``methodAttribute`` is enabled)

.. note::
    | Parameter from attribute will have less priority.
    | Only attributes marked with class ``Infuse()`` will be counted (on method or argument)
    | ``__constructor()`` attributes will be ignored.

* Resolve named parameters first then the others
* Resolve value by following order (whichever available)

    * check definition
    * check if resolvable class (also if named parameter is injectable)
    * direct value placement from available supplies

Property resolution steps
-------------------------

In between **constructor** & **method** resolution, class properties are resolved (if enabled in option).

* resolve current class first then also the parent class (if available)
* resolve if property supplied via ``registerProperty()``
* check if initiated via class ``Infuse()``
* resolve if type hint indicates any resolvable class
* if found in definition list, resolve
* if any function exists with given name, resolve

Method Selection
----------------

When container scans through the classes, it selects method using below priority:

* Method already provided, using ``call()``
* Look for method, registered via ``registerMethod()``
* Method provided via ``callOn`` constant
* Method name found via ``defaultMethod``

If none found after above steps, no method will be resolved.
