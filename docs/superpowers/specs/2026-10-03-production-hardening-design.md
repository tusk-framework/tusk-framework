# Tusk Framework Production Hardening Design

## Goal

Tornar o framework seguro e previsível para aplicações PHP de longa duração, preservando as interfaces públicas existentes sempre que possível.

## Scope

- Corrigir a propagação do contexto de rota e a execução de `Authenticated`/`Can`.
- Fazer autenticação falhar de modo seguro e alinhar os guards com `Tusk\\Web\\Http\\Request`.
- Reduzir respostas de erro para detalhes seguros, mantendo detalhes nos logs.
- Propagar uploads PSR-7 corretamente e garantir reset request-scoped por requisição.
- Tornar `DatabaseQueue::pop()` atômico e recuperar jobs abandonados de acordo com uma política explícita.
- Adicionar testes de regressão e documentação de limites.

Features ainda incompletas, como novos transportes e cliente cloud HTTP real, não serão simuladas como prontas; serão registradas separadamente.

## Cross-repository contract

O Engine entrega requests PSR-7 pelo worker RoadRunner. O Framework deve preservar os campos da requisição e nunca enviar detalhes de exceção ao cliente.

## Design

### Request and security context

`HttpKernel` adicionará `_controller` e `_action` à requisição antes de construir o pipeline. `SecurityMiddleware` poderá então avaliar atributos de classe e método antes do controller. `Request` ganhará accessors mínimos para headers e inputs usados pelos guards, sem duplicar a API PSR-7.

JWT exigirá `JWT_SECRET` não vazio com pelo menos 32 bytes; configuração ausente ou curta fará o guard falhar fechado. Bearer token será a fonte padrão; token em query string ficará desativado por padrão. Falhas de autenticação não devem revelar detalhes criptográficos.

### Error policy

O kernel registrará a exceção e um request ID no log. A resposta pública será genérica em produção, tanto para HTML quanto JSON. Detalhes só serão exibidos quando `APP_DEBUG` tiver um valor truthy (`1`, `true`, `yes` ou `on`), com default `false`.

### Long-lived runtime

O adapter nativo criará objetos PSR-7 para uploads e encerrará cada ciclo com reset request-scoped. O reset não deve duplicar hooks nem manter identidade de usuário entre requisições.

### Database queue

O claim de job será indivisível para workers concorrentes. Jobs em `processing` terão timestamp de reserva e poderão ser recuperados após 300 segundos sem renovação, com esse valor exposto como parâmetro configurável. A mudança deve funcionar com a abstração DBAL existente e ser testada com dois consumidores.

## Verification

- PHPUnit: guards, middleware, kernel, uploads, isolamento de escopo, fila e regressões de compilação.
- PHPStan no nível configurado.
- Testes de contrato do worker RoadRunner.
- Execução end-to-end será obrigatória quando PHP estiver disponível; até lá, o bloqueio de ambiente será documentado.

## Non-goals

- Reescrever o container ou trocar PSR-7.
- Implementar proxy HTTP cloud ou um novo protocolo de transporte nesta entrega.
