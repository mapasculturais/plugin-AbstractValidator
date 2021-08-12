# plugin-AbstractValidator
Plugin que agrega funcionalidades gerais de validação para uso pelos demais validadores

## Exemplo de Configuração

É necessário definir o tipo de agente, conforme configuração da instalação, para o usuário validador.
```
        "AbstractValidator" => [
            "namespace" => "AbstractValidator",
            "config" => [
                "validator_agent_type" => 2,
            ]
        ],
```
