# plugin-AbstractValidator
Plugin que agrega funcionalidades gerais de validação para uso pelos demais validadores

## Exemplo de Configuração

```
        "AbstractValidator" => [
            "namespace" => "AbstractValidator",
            "config" => [
                "is_opportunity_managed_handler" => function ($opportunity) {
                    return ($opportunity->id == 42);
                },
            ]
        ],
```
