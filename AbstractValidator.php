<?php

namespace AbstractValidator;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use MapasCulturais\i;

/**
 * @property-read \MapasCulturais\Entities\User $user
 * @property-read string $slug
 * @property-read string $name
 *
 * @package StreamlinedOpportunity
 */
abstract class AbstractValidator extends \MapasCulturais\Plugin
{
    /**
     * Usuário validador
     *
     * @var MapasCulturais\Entities\User;
     */
    protected $_user = null;

    function __construct(array $config=[])
    {
        $slug = $this->getSlug();
        $config += [
            // se true, só considera a validação deste validador na consolidação
            "is_absolute" => false,
            // se true, só consolida se houver ao menos uma homologação
            "homologation_required" => true,
            // lista de validadores requeridos na consolidação
            "required_validations" => (array) json_decode(env(strtoupper($slug) . "_REQUIRED_VALIDATIONS", "[]")),
        ];
        parent::__construct($config);
        return;
    }

    /**
     * Inicializa o plugin
     * Cria o usuário avaliador se este ainda não existir
     *
     * @return void
     */
    function _init()
    {
        $app = App::i();
        $app->hook("slim.before", function () {
            $this->createUserIfNotExists();
            return;
        });
        $plugin = $this;
        $user = $this->getUser();
        $app->hook("opportunity.registrations.reportCSV", function (Opportunity $opportunity, $registrations, &$header, &$body) use ($app, $user, $plugin) {
            $em = $opportunity->getEvaluationMethod();
            $_evaluations = $app->repo("RegistrationEvaluation")->findBy(["user" => $user, "registration" => $registrations]);
            $evaluations_status = [];
            $evaluations_obs = [];
            foreach ($_evaluations as $eval) {
                $evaluations_status[$eval->registration->number] = $em->valueToString($eval->result);
                $evaluations_obs[$eval->registration->number] = $eval->evaluationData->obs ?? json_encode($eval->evaluationData);
            }
            $header[] = sprintf(i::__("%s - status"), $plugin->getName());
            $header[] = sprintf(i::__("%s - observações"), $plugin->getName());
            foreach ($body as $i => $line) {
                $body[$i][] = $evaluations_status[$line[0]] ?? null;
                $body[$i][] = $evaluations_obs[$line[0]] ?? null;
            }
            return;
        });
        /**
         * @TODO: implementar para metodo de avaliação documental
         */
        $app->hook("entity(Registration).consolidateResult", function (&$result, $caller) use ($plugin, $app) {
            // só aplica quando a consolidação partir da avaliação do usuário validador
            if (!$caller->user->equals($plugin->getUser())) {
                return;
            }
            $can_consolidate = true;
            $evaluations = $app->repo("RegistrationEvaluation")->findBy(["registration" => $this, "status" => 1]);
            /**
             * Se a consolidação requer homologação, verifica se existe alguma
             * avaliação de um usuário que não é um validador
             * (validadores são usuários criados por plugins baseados nessa classe)
             */
            if ($plugin->config["homologation_required"]) {
                $can = false;
                foreach ($evaluations as $eval) {
                    if (!$eval->user->validator_for) {
                        $can = true;
                        break;
                    }
                }
                if (!$can) {
                    $can_consolidate = $can;
                }
            }
            /**
             * Se a consolidação requer outras validações, verifica se existe alguma
             * a avaliação dos usuários validadores
             */
            if ($validacoes = $plugin->config["required_validations"]) {
                foreach ($validacoes as $slug) {
                    $can = false;
                    foreach ($evaluations as $eval) {
                        if ($eval->user->validator_for == $slug) {
                            $can = true;
                            break;
                        }
                    }
                    if (!$can) {
                        $can_consolidate = false;
                    }
                }
            }
            if ($can_consolidate) {
                if ($plugin->config["is_absolute"]) {
                    $result = $caller->result;
                }
            // se não pode consolidar, coloca string 'validado por {nome}' ou 'invalidado por {nome}'
            } else {
                $nome = $plugin->getName();
                $string = "";
                if ($caller->result == "10") {
                    $string = sprintf(i::__("validado por %s"), $nome);
                } else if ($caller->result == "2") {
                    $string = sprintf(i::__("invalidado por %s"), $nome);
                } else if ($caller->result == "3") {
                    $string = sprintf(i::__("não selecionado por %s"), $nome);
                } else if ($caller->result == "8") {
                    $string = sprintf(i::__("suplente por %s"), $nome);
                }
                // se não tem valor ainda ou se está atualizando:
                if (!$this->consolidatedResult || (count($evaluations) <= 1)) {
                    $result = $string;
                } else if (strpos($this->consolidatedResult, $nome) === false) {
                    $current_result = $this->consolidatedResult;
                    if ($current_result == "10") {
                        $current_result = i::__("selecionada");
                    } else if ($current_result == "2") {
                        $current_result = i::__("inválida");
                    } else if ($current_result == "3") {
                        $current_result = i::__("não selecionada");
                    } else if ($current_result == "8") {
                        $current_result = i::__("suplente");
                    }
                    $result = sprintf(i::__("%s, %s"), $current_result, $string);
                } else {
                    $result = $this->consolidatedResult;
                }
            }
            return;
        });
        $app->hook("GET(opportunity.single):before", function () use ($app, $plugin) {
            $result = false;
            $slug = $plugin->getSlug();
            $opportunity = $this->requestedEntity;
            $app->applyHookBoundTo($opportunity, "validator($slug).isOpportunityManaged", [&$result]);
            if ($result) {
                $user = $plugin->getUser();
                if (!in_array($opportunity->id, $user->evaluation_access)) {
                    $plugin->makeUserEvaluatorIn($opportunity);
                }
            }
            return;
        });
        return;
    }

    /**
     * Registro
     *
     * @return void
     */
    function register()
    {
        $app = App::i();
        // registra o controlador
        $app->registerController($this->getSlug(), $this->getControllerClassname());
        $this->registerUserMetadata("evaluation_access", [
            "label" => "Oportunidades onde o usuário é avaliador",
            "type" => "json",
            "private" => false,
            "default_value" => "[]"
        ]);
        $this->registerUserMetadata("validator_for", [
            "label" => "Se o usuário é validador, o slug do plugin",
            "type" => "string",
            "private" => false,
            "default_value" => false
        ]);
        return;
    }

    /**
     * Retorna os ids das oportunidades gerenciadas
     */
    protected function getOpportunitiesIds($target_slug=null)
    {
        $app = App::i();
        $result = [];
        $slug = $this->getSlug();
        $app->applyHook("validator($slug).getManagedOpportunities", [&$result, $target_slug]);
        return $result;
    }

    /**
     * Retorna o authUid do usuário do plugin validador
     *
     * @return string
     */
    protected function getAuthUid(): string
    {
        return ($this->getSlug() . "@validator");
    }

    /**
     * Verifica se o usuário do plugin validador já existe no banco
     *
     * @return bool
     */
    protected function userExists(): bool
    {
        return ((bool) $this->getUser());
    }

    /**
     * Cria o usuário do plugin validador se este ainda não existir
     *
     * @return bool se criou ou não criou o usuário
     */
    protected function createUserIfNotExists()
    {
        $app = App::i();
        if (!$this->userExists()) {
            $app->disableAccessControl();
            $user = new User;
            $user->authProvider = __CLASS__;
            $user->authUid = $this->getAuthUid();
            $user->email = $this->getAuthUid();
            $user->validator_for = $this->getSlug();
            $app->em->persist($user);
            $app->em->flush();
            $agent = new Agent($user);
            $agent->name = $this->getName();
            $agent->type = $this->config["validator_agent_type"];
            $agent->status = Agent::STATUS_ENABLED;
            $agent->save();
            $app->em->flush();
            $user->profile = $agent;
            $user->save(true);
            $app->enableAccessControl();
            return true;
        }
        return false;
    }

    function getUser()
    {
        $app = App::i();
        return $app->repo("User")->findOneBy(["authUid" => $this->getAuthUid()]);
    }

    /**
     * Definine o usuário avaliador como avaliador na oportunidade
     *
     * @param Opportunity $opportunity
     * @return void
     */
    protected function makeUserEvaluatorIn(Opportunity $opportunity)
    {
        $app = App::i();
        $user = $this->getUser();
        $app->disableAccessControl();
        $relation = new EvaluationMethodConfigurationAgentRelation;
        $relation->owner = $opportunity->evaluationMethodConfiguration;
        $relation->agent = $user->profile;
        $relation->group = "group-admin";
        $relation->hasControl = true;
        $relation->save(true);
        $ids = $user->evaluation_access;
        $ids[] = $opportunity->id;
        $user->evaluation_access = $ids;
        $user->save(true);
        $app->disableAccessControl();
        return;
    }

    /**
     * Retorna o nome da instituição avaliadora
     *
     * @return string
     */
    abstract function getName(): string;

    /**
     * Retorna o slug da instituição avaliadora
     *
     * @return string
     */
    abstract function getSlug(): string;

    /**
     * Retorna o nome da classe do controlador.
     *
     * Será registrado no sistema com o slug do plugin validador
     *
     * @return string
     */
    abstract function getControllerClassName(): string;

    /**
     * Verifica se a inscrição está apta a ser validada
     *
     * @param \MapasCulturais\Entities\Registration $registration
     *
     * @return boolean
     */
    abstract function isRegistrationEligible(Registration $registration): bool;
}
