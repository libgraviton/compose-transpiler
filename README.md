## compose transpiler

[![CI](https://github.com/libgraviton/compose-transpiler/actions/workflows/ci.yml/badge.svg)](https://github.com/libgraviton/compose-transpiler/actions/workflows/ci.yml)
[![Coverage Status](https://coveralls.io/repos/github/libgraviton/compose-transpiler/badge.svg?branch=develop)](https://coveralls.io/github/libgraviton/compose-transpiler?branch=develop)
[![Docker Pulls](https://img.shields.io/docker/pulls/graviton/compose-transpiler.svg)](https://hub.docker.com/r/graviton/compose-transpiler/)
[![Docker Automated](https://img.shields.io/docker/automated/graviton/compose-transpiler.svg)](https://hub.docker.com/r/graviton/compose-transpiler/)

A small script that transpiles yaml structures (like compose files) from templates.

The main use case for this script is to reduce repetition in YAML structures such as Docker Compose and/or _stack files_
within a setup that has many different stages and/or tenants. In such a setup, many configurations are basically the same
but different for each tenant/stage. 

This scripts helps to cover all that while still not repeat yourself to death and making errors while doing that.

This transpiler takes a _profile_ (which can inherit/extend another one) and with the help from the templates generate a 
fully valid Docker Compose / Stack file for Docker Swarm (v12.0+).

### Prereqs

You create a basic directory structure for the templates:

```
./_templates
./_templates/base // footer and header templates
./_templates/components // components
./_templates/mixins // mixins
```

Now, `./_templates` is our `$templateDir`.

Note that all files ending in `.tmpl.yml` are treated as Twig templates and can thus
contain [twig template language constructs](https://twig.symfony.com/doc/2.x/).

### How it works

Given the following files:

`./_templates/base/header.tmpl.yml`
```yaml
version: 3.2
```

`./_templates/base/footer.tmpl.yml`
```yaml
networks:
  default:
    driver: overlay
```

`./_templates/components/redis.tmpl.yml`
```yaml
image: 'redis:latest'
environment:
  VAR1=a
```

You can now write a file for the transpiler (called a _profile_):

`./myapp.yml`
```yaml
components:
  redis:
```

And then generate your file as follows:

```
$t = new \Graviton\ComposeTranspiler\Transpiler(__DIR__.'/_templates');
$t->transpile("./myapp.yml", "./transpiled.yml");
```

You will find this in `./transpiled.yml`:

```yaml
version: 3.2
services:
  redis:
    image: 'redis:latest'
    environment:
      VAR1=a
networks:
  default:
    driver: overlay    
```

### Mixins

Mixins are small snippets that can be added to any component. It's best used for things
that many components share (like a common environment var).

Based on the above example, you create the file:

`./_templates/mixins/sentry-dsn.tmpl.yml`
```yaml
environment:
  SENTRY_DSN="http://mysentry/urlurlurl"
```

Then add to your spec:

`./myapp.yml`
```yaml
components:
  redis:
    mixins:
      sentry-dsn:
```

Will produce:

```yaml
version: 3.2
services:
  redis:
    image: 'redis:latest'
    environment:
      VAR1=a
      SENTRY_DSN="http://mysentry/urlurlurl"
networks:
  default:
    driver: overlay    
```

### Exposing

There is a special component template that must be named `_expose.tmpl.yml`.

The purpose of this is that components that should be exposed via a separate container
(like a proxying nginx) can notate a small line in the spec and that get added one exposing
container.

Example in your spec:

`./myapp.yml`
```yaml
components:
  redis:
    expose:
      exposePort: 80
      upstreamUrl: http://redis:9000
    mixins:
      sentry-dsn:
```

Now that the special `expose` key is present under the component, transpiler will search for the
`_expose.tmpl.yml` template.

Let's define this template as follows:

`./_templates/components/_expose.tmpl.yml`
```yaml
image: 'my-nginx:latest'
ports:
  - {{ exposePort }}:9080
environment:
  - UPSTREAM_URL={{ upstreamUrl}}
```

The resulting yaml file after generation will be:

```yaml
version: 3.2
services:
  redis:
    image: 'redis:latest'
    environment:
      VAR1=a
      SENTRY_DSN="http://mysentry/urlurlurl"
  redis-expose:
    image: 'my-nginx:latest'
    ports:
      - 80:9080
    environment:
      - UPSTREAM_URL=http://redis:9000 
networks:
  default:
    driver: overlay
```

### Passing vars to templates

In a generic fashion, you can pass arbitrary variables to your templates.

So you can notate: 

`./myapp.yml`
```yaml
components:
  redis:
    myVar: 1
    whatEverValue: whynot
```

And you will have `myVar` and `whatEverValue` available as Twig variables in
your `redis.tmpl.yml` template.

### Profile inheritance

Profiles can inherit from each other. Given a base file:

`./myapp.yml`
```yaml
components:
  redis:
  mysql:
``` 

Another file can extend that file using the special `_inheritance` key:

`./myapp-other.yml`
```yaml
_inheritance:
  extends: ./myapp.yml
components:
  postgres:
```

The result will then be:

```yaml
components:
  redis:
  mysql:
  postgres:
```

and then it will be transpiled with that profile.

You can also unset paths in the parent profile(s). Taken the above example, `./myapp-other.yml` could be:

`./myapp-other.yml`
```yaml
_inheritance:
  extends: ./myapp.yml
  unsets:
    - "components.mysql"
components:
  postgres:
```

Which will then result in:

```yaml
components:
  redis:
  postgres:
```

You can "unset" all simple paths by using dot notation. There is no support for more complex operations then a dot.
