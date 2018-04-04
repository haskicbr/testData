<?php

namespace App\Controllers;

use App\Exporters\Export;
use App\Helpers\App;
use App\Helpers\ArrayHelper;
use App\Lib\Db\ActiveRecordCollection;
use App\Lib\Web\Controller;
use App\Models\CandidateMultiModel;
use App\Models\Client;
use App\Models\FormModel;
use App\Models\Forms\CandidateMultiForm;
use App\Models\MultiModel;
use App\Models\NormativeGroup;
use App\Models\ProjectUser;
use App\Models\ProjectUserPresetFormField;
use App\Models\ProjectUserReportListType;
use App\Models\ProjectUserTest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectTest;
use App\Models\Candidate;
use App\Models\Forms\ProjectForm;
use App\Models\Role;
use App\Models\Test;
use App\Models\TestInstrument;
use App\Models\TestNormativeGroup;
use App\Models\TestType;
use App\Widgets\ClientsBar;
use App\Widgets\Columns;
use App\Widgets\Lists\ProjectsList;
use App\Widgets\Lists\ProjectsUsersList;
use App\Widgets\Lists\ProjectsUsersProfessionalTestsReportsList;
use App\Widgets\Lists\ProjectsUsersTestsList;
use App\Widgets\Lists\ProjectsUsersTestsQuestionsAnswersList;
use yii\base\Exception;
use yii\helpers\Inflector;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;

/**
 * @package App\Controllers
 * @method \App\Models\Client findClient($clientId, $permission = null)
 * @method Project findProject($projectId, $permission = null)
 * @method ProjectUser findProjectUser($where, $permission = null)
 * @method ProjectTest findProjectTest($projectTestId, $permission = null)
 * @method ProjectUserTest findProjectUserTest($where, $permission = null)
 * @method Candidate findCandidate($candidateId)
 * @method NormativeGroup findNormativeGroup($nGroupId, $permission = null)
 */
class ProjectsController extends Controller
{
    public $layout = 'admin';

    /**
     * Страница со списком доступных проектов
     *
     * @return string
     * @throws
     */
    public function actionList()
    {
        if (!$this->getUser()->hasPermission(Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('Доступ к данному разделу закрыт');
        }

        return $this->renderContent(Columns::widget([
            'centerWidget' => [
                'class'     => ProjectsList::class,
                'client'    => false,
                'title'     => 'Все проекты',
                'emptyText' => 'Не найдено ни одного проекта',
                'returnUrl' => $this->getRequestUrl(),

                'searchQuery' => (\Yii::$app->request->get('q')) ? trim(\Yii::$app->request->get('q')) : null,
            ],
        ]));
    }

    /**
     * @param mixed $projectId
     *
     * @return string
     * @throws
     */
    public function actionView($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');

        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет доступа к проекту данного клиента');
        }

        return $this->renderContent(Columns::widget([
            'centerWidget' => [
                'class'     => ProjectsList::class,
                'client'    => false,
                'id'        => $projectId,
                'isOneItem' => true,
                'title'     => 'Проект',
                'emptyText' => 'Нет ни одного проекта',
                'returnUrl' => $this->getRequestUrl(),
            ],
        ]));
    }

    /**
     * Создание нового проекта
     *
     * @param mixed $clientId
     *
     * @return string|\yii\web\Response
     * @throws
     */
    public function actionAdd($clientId)
    {
        /** @var Client $client */
        $client = $this->findOrFail(Client::class, $clientId, 'Клиент не обнаружен');

        if (!$client->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа проектам данного клиента');
        }

        $project = new Project();
        $project->setClient($client);
        $project->setAdminUser($this->getUser());

        return $this->saveProject($project, 'Новый проект добавлен');
    }

    /**
     * Редактирование проекта
     *
     * @param mixed $projectId
     *
     * @return string|\yii\web\Response
     * @throws
     */
    public function actionEdit($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа к проекту данного клиента');
        }

        // Завершённый проект нельзя редактировать
        if ($project->isStatusFinished()) {
            return $this->redirect($project->html()->getUrlToCandidates());
        }

        return $this->saveProject($project, $project->html()->linkToView('Информация о проекте «{link}» сохранена'));
    }

    /**
     * Удаление проекта
     *
     * @param $projectId
     *
     * @return string|\yii\web\Response
     * @throws ForbiddenHttpException
     */
    public function actionRemove($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа к проекту данного клиента');
        }
        $projectName = $project->html()->getName();
        $clientName  = $project->getClient()->getName();
        $returnUrl   = $this->getReturnUrl($project->getClient()->html()->getUrlToView());

        if (\Yii::$app->request->isPost) {
            try {
                if ($project->delete()) {
                    flash_success($project->html()->linkToView('Проект «{link}» удалён', $projectName));
                } else {
                    flash_error($project->html()->linkToView('Не удалось удалить проект «{link}»', $projectName));
                }
            } catch (HttpException $e) {
                flash_error($e->getMessage());
            }

            return $this->redirect($returnUrl);
        }

        return $this->renderConfirmRemove(
            "Вы действительно хотите <strong class='text-danger'>удалить</strong> проект <strong>«{$projectName}»</strong> клиента «{$clientName}»?",
            $returnUrl
        );
    }

    /**
     * Список кандидатов в проекте
     *
     * @param mixed $projectId
     *
     * @return string
     * @throws
     */
    public function actionCandidates($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет доступа к проекту данного клиента');
        }

        return $this->renderContent(Columns::widget([
            'leftSideWidget' => [
                'class'  => ClientsBar::class,
                'client' => $project->getClient(),
            ],
            'centerWidgets'  => [
                [
                    'class'     => ProjectsList::class,
                    'title'     => 'Проект',
                    'subtitle'  => $project->getClientHtml()->linkToView('клиента {link}'),
                    'isOneItem' => true,
                    'client'    => $project->getClient(),
                    'id'        => $projectId,
                    'returnUrl' => $this->getRequestUrl(),
                ],
                [
                    'class'     => ProjectsUsersList::class,
                    'project'   => $project,
                    'title'     => 'Кандидаты',
                    'subtitle'  => $project->html()->linkToView('в проекте «{link}»'),
                    'emptyText' => 'Не найдено ни одного кандиадата',
                    'returnUrl' => $this->getRequestUrl(),
                ],
            ],
        ]));
    }

    /**
     * Добавление кандидата в проект
     *
     * @internal Добавление-редактирование кандидата это пока самое неочевидное место в системе ((
     * подробности смотрите в комментарии ниже (метож редактирования)
     *
     * @param mixed $projectId
     *
     * @return string
     * @throws
     */
    public function actionAddCandidate($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_USERS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа к кандидатам проекта данного клиента');
        }

        if ($project->isStatusFinished()) {
            return $this->redirect($project->html()->getUrlToCandidates());
        }

        return $this->multiSaveAndRedirect(
            function () use ($project) { // <-- значения модели на форме по умолчанию
                $multiModel = new CandidateMultiModel(['project' => $project]);
                $multiModel->addModel(new Candidate());
                if ($project->getPresetFormId() && ! $project->getPresetFormCandidateOnly()) {
                    $multiModel->addModel(new FormModel($project->getPresetForm()));
                }
                return $multiModel;
            },
            function ($attributes) use ($project) { // выполняется на POST запросах только

                if (isset($attributes['Candidate_login'])) {
                    $candidate = Candidate::findByLogin($attributes['Candidate_login']);
                }

                if (empty($candidate)) {
                    $candidate = new Candidate();
                    $candidate->setRoleAlias(Role::CANDIDATE);
                    $candidate->setAdminUser($this->getUser());
                }

                $multiModel = new CandidateMultiModel(['project' => $project]);
                $multiModel->addModel($candidate, [
                    'login', 'email', 'password',
                    'lastName', 'firstName', 'middleName',
                    'agreementConfirm' /*, 'roleAlias'*/
                ]);

                // сохранятр формы и сама форма
                $formModel = null;
                if ($project->getPresetFormId() && ! $project->getPresetFormCandidateOnly()) {
                    $formModel = new FormModel(
                        $project->getPresetForm(),
                        new ProjectUserPresetFormField()
                    );

                    // блок для испльзования ранее заполненой формы
                    $formModel->on('beforeValidate', function () use ($candidate, $formModel) {
                        // заполняем предыдущими значениями?
                        if ($candidate->getId()) {
                            $fieldStorage = $formModel->getFormFieldStorage();
                            $fieldStorage->setUser($candidate);
                            $presetFormData = $fieldStorage->getFormValuesByForm();

                            $attributes = array_filter($formModel->getAttributes(), function ($el) {
                                return ($el === null || $el === '') ? false : true;
                            });
                            $attributes += $presetFormData;

                            $formModel->setFormFieldStorage($fieldStorage);
                            $formModel->load($attributes, '');
                            //p($attributes, $formModel->getAttributes());die();
                        }
                    });

                    $multiModel->addModel($formModel, null, null); // не устанавливаем метод, чтобы не сохранялись данные у этой модели! (см кандидата выше)
                }

                // TODO такого быть не должно, придумать как исправить при первой возможности и хорошем расположении духа
                if ($candidate->getIsNewRecord()) {
                    $candidate->on('afterInsert', function () use ($project, $candidate, $formModel) {

                        $pu = ProjectUser::create($project, $candidate);
                        if ($formModel) {
                            $fieldStorage = $formModel->getFormFieldStorage();
                            $fieldStorage->setTargetObject($pu);

                            $formModel->setFormFieldStorage($fieldStorage);
                            $formModel->save(false);
                        }
                    });

                } else {

                    $candidate->on('afterValidate', function () use ($project, $candidate) {
                        if ($candidate->hasErrors()) return false;

                        $pu = ProjectUser::lookup($project, $candidate);
                        if ($pu) {
                            $candidate->addError('login', 'Этот кандидат уже существует в данном проекте!');
                            return false;
                        }
                        return true;
                    });

                    $candidate->on('afterUpdate', function () use ($project, $candidate, $formModel) {

                        $pu = ProjectUser::create($project, $candidate);
                        if ($formModel) {
                            $fieldStorage = $formModel->getFormFieldStorage();
                            $fieldStorage->setTargetObject($pu);

                            $formModel->setFormFieldStorage($fieldStorage);
                            $formModel->save(false);
                        }
                    });

                    // надо убрать из пришедших данных все, которые касаются модели кандидата
                    // и оставить только те, что для пресета
                    $attributes = array_filter($attributes, function ($key) {
                        return mb_strpos($key, 'Candidate', 0) === false;
                    }, ARRAY_FILTER_USE_KEY);

                }

                $multiModel->setAttributes($attributes, false);
                return $multiModel;
            },
            function (array $records) use ($project) { // <-- вывод формы
                return new CandidateMultiForm($records, [
                    'project'                    => $project,
                    'parts.uploadFile'           => true,
                    'parts.buttonMore'           => true,
                    'options.buttonMore.text'    => 'Добавить ещё кандидата',
                    'formConfig.options.enctype' => 'multipart/form-data',
                    'options.cancelButton.url'   => $this->getReturnUrl($project->html()->getUrlToCandidates()),
                    'options.title.text'         => 'Добавление кандидатов',
                    'options.subtitle.text'      => $project->html()->linkToView('в проект «{link}»'),
                ]);
            },
            function () use ($project) {
                return $project->html()->getUrlToCandidates();
            },
            function ($records) {
                return count($records) > 1 ? 'Кандидаты добавлены' : 'Кандидат добавлен';
            }
        );
    }

    /**
     * @param mixed $projectId
     * @param mixed $userId
     *
     * @internal Форма работает через CandidateMultiForm , который конфигурируется двумя моделями, которые в свою очередь конфигурируются
     * -- в процессе сохранения
     * -- в процессе инициализации
     *
     * При добавлении кандидатов в проект также для одной модели отменяется автоматическое сохранения данных (вешается на событие в другой модели)
     *
     * @return string|\yii\web\Response
     * @throws
     */
    public function actionEditCandidate($projectId, $userId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_USERS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа к кандидатам проекта данного клиента');
        }

        /** @var Candidate $candidate */
        $candidate = $this->findOrFail(Candidate::class, $userId, 'Указанный кандидат не обнаружен');

        $formModel = null;
        if ($project->getPresetFormId() && ! $project->getPresetFormCandidateOnly()) { // если есть пресет форма!

            $projectUser                = ProjectUser::lookup($project, $candidate);
            $projectUserPresetFormField = new ProjectUserPresetFormField(['TargetObject' => $projectUser]);
            $presetFormData             = $projectUserPresetFormField->getFormValuesByTargetObject();

            $formModel = new FormModel($project->getPresetForm(), $projectUserPresetFormField);
            $formModel->load($presetFormData, '');
        }

        return $this->multiSaveAndRedirect(
            function () use ($candidate, $project, $formModel) { // <-- значения модели на форме по умолчанию
                $multiModel = new CandidateMultiModel(['project' => $project]);
                $multiModel->addModel($candidate);
                if ($project->getPresetFormId() && ! $project->getPresetFormCandidateOnly()) {
                    $multiModel->addModel($formModel);
                }
                return $multiModel;
            },
            function ($attributes) use ($candidate, $project, $formModel) {

                $multiModel = new CandidateMultiModel(['project' => $project]);
                $multiModel->addModel($candidate, [
                    'login', 'email', 'password',
                    'lastName', 'firstName', 'middleName',
                    'agreementConfirm' /*, 'roleAlias'*/
                ]);

                if ($project->getPresetFormId() && ! $project->getPresetFormCandidateOnly()) {
                    $multiModel->addModel($formModel);
                }
                $multiModel->setAttributes($attributes, false);
                return $multiModel;
            },
            function ($records) use ($candidate, $project, $formModel) {

                return new CandidateMultiForm($records, [
                    'project'                  => $project,
                    'options.cancelButton.url' => $this->getReturnUrl($project->html()->getUrlToCandidates()),
                    'options.title.text'       => 'Редактирование кандидата',
                    'options.subtitle.text'    => ($project->getPresetFormId() && ! $project->getPresetFormCandidateOnly())
                        ? $project->html()->linkToView('в проекте «{link}»')
                        : '',
                ]);
            },
            function () use ($project) {
                return $project->html()->getUrlToCandidates();
            },
            function () {
                return 'Информация о кандидате обновлена';
            });
    }

    /**
     * Просмотр кандидата проекта
     *
     * @param mixed $projectId
     * @param mixed $userId
     *
     * @return string
     * @throws
     */
    public function actionViewCandidate($projectId, $userId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_USERS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа к кандидатам проекта данного клиента');
        }

        /** @var ProjectUser $projectUser */
        $projectUser = $this->findOrFail(ProjectUser::class, ['project_id' => $projectId, 'user_id' => $userId], 'Кандидат не обнаружен');

        return $this->renderContent(Columns::widget([
            'leftSideWidget' => [
                'class'  => ClientsBar::class,
                'client' => $project->getClient(),
            ],
            'centerWidgets'  => [
                [
                    'class'     => ProjectsList::class,
                    'title'     => 'Проект',
                    'subtitle'  => $project->getClientHtml()->linkToView('клиента {link}'),
                    'isOneItem' => true,
                    'id'        => $projectId,
                    'returnUrl' => $this->getRequestUrl(),
                ],
                [
                    'class'     => ProjectsUsersList::class,
                    'project'   => $project,
                    'title'     => 'Кандидат',
                    'subtitle'  => $project->html()->linkToView('в проекте «{link}»'),
                    'emptyText' => 'Не найдено ни одного кандиадата',
                    'id'        => $projectUser->getId(),
                    'isOneItem' => true,
                    'returnUrl' => $this->getRequestUrl(),
                ],
            ],
        ]));
    }

    /**
     * Просмотр результатов тестирований выбранного кандидата
     *
     * @param mixed $projectId
     * @param mixed $userId
     *
     * @return string
     * @throws
     */
    public function actionViewUsersTests($projectId, $userId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет доступа к результатам кандидатам данного проекта');
        }
        
        /** @var ProjectUser $projectUser */
        $projectUser = $this->findOrFail(ProjectUser::class, ['project_id' => $projectId, 'user_id' => $userId], 'Кандидат не обнаружен');

        return $this->renderContent(Columns::widget(
            [
                'leftSideWidget' => [
                    'class'  => ClientsBar::class,
                    'client' => $project->getClient(),
                ],
                'centerWidgets'  => [
                    [
                        'class'     => ProjectsList::class,
                        'title'     => 'Проект',
                        'subtitle'  => $project->getClientHtml()->linkToView('клиента {link}'),
                        'isOneItem' => true,
                        'id'        => $projectId,
                        'returnUrl' => $this->getRequestUrl(),
                    ],
                    [
                        'class'     => ProjectsUsersList::class,
                        'project'   => $project,
                        'title'     => 'Кандидат',
                        'subtitle'  => $project->html()->linkToView('в проекте «{link}» ' . $project->getClientHtml()->linkToView('клиента {link}')),
                        'emptyText' => 'Не найдено ни одного кандидата',
                        'id'        => $projectUser->getId(),
                        'isOneItem' => true,
                        'returnUrl' => $this->getRequestUrl(),
                    ],
                    [
                        'class'       => ProjectsUsersTestsList::class,
                        'projectUser' => $projectUser,
                        'title'       => 'Тесты',
                        'subtitle'    => $projectUser->html()->linkToView('кандидата {link}'),
                        'emptyText'   => 'На кандидата не назначено ни одного теста',
                        'returnUrl'   => $this->getRequestUrl(),
                    ],
                ],
            ]
        ));
    }

    /**
     * Просмотр ответов на текст указнного кандидата в указанном проекте
     *
     * @param mixed $projectId
     * @param mixed $userId
     * @param mixed $testId
     *
     * @return string
     * @throws
     */
    public function actionViewUsersTestsAnswers($projectId, $userId, $testId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет доступа к результам кандидатам данного проекта ');
        }

        /** @var ProjectUserTest $projectUserTest */
        $projectUserTest = $this->findOrFail(ProjectUserTest::class, ['project_id' => $projectId, 'user_id' => $userId, 'test_id' => $testId], 'Результаты тестирования кандидата не обнаружены');
        $projectUser     = $projectUserTest->getProjectUser();

        return $this->renderContent(Columns::widget(
            [
                'leftSideWidget' => [
                    'class'  => ClientsBar::class,
                    'client' => $project->getClient(),
                ],
                'centerWidgets'  => [
                    [
                        'class'     => ProjectsList::class,
                        'title'     => 'Проект',
                        'subtitle'  => $project->getClientHtml()->linkToView('клиента {link}'),
                        'isOneItem' => true,
                        'id'        => $projectId,
                        'returnUrl' => $this->getRequestUrl(),
                    ],
                    [
                        'class'     => ProjectsUsersList::class,
                        'project'   => $project,
                        'title'     => 'Кандидат',
                        'subtitle'  => $project->html()->linkToView('в проекте «{link}» ' . $project->getClientHtml()->linkToView('клиента {link}')),
                        'emptyText' => 'Не найдено ни одного кандиадата',
                        'id'        => $projectUser->getId(),
                        'isOneItem' => true,
                        'returnUrl' => $this->getRequestUrl(),
                    ],
                    [
                        'class'       => ProjectsUsersTestsList::class,
                        'projectUser' => $projectUser,
                        'title'       => 'Тест',
                        'subtitle'    => $projectUser->html()->linkToView('кандидата {link}'),
                        'emptyText'   => 'На кандидата не назначено ни одного теста',
                        'id'          => $projectUserTest->getId(),
                        'isOneItem'   => true,
                        'returnUrl'   => $this->getRequestUrl(),
                    ],
                    [
                        'class'           => ProjectsUsersTestsQuestionsAnswersList::class,
                        'projectUserTest' => $projectUserTest,
                        'title'           => 'Ответы',
                        'subtitle'        => $projectUserTest->getTest()->html()->linkToView('на тест «{link}»'),
                        'returnUrl'       => $this->getRequestUrl(),
                    ],
                ],
            ]
        ));
    }

    /**
     * Удаление результатов прохождения теста кандидатом
     *
     * @param $projectId
     * @param $userId
     * @param $testId
     *
     * @return mixed
     * @throws
     */
    public function actionRemoveUsersTestsAnswers($projectId, $userId, $testId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_USERS_RESULTS_REMOVE)) {
            throw new ForbiddenHttpException('У вас нет доступа к результам кандидатам данного проекта ');
        }

        if (!$project->isStatusStarted()) {
            return $this->redirect($project->html()->getUrlToCandidates());
        }

        /** @var ProjectUserTest $projectUserTest */
        $projectUserTest = $this->findOrFail(ProjectUserTest::class, ['project_id' => $projectId, 'user_id' => $userId, 'test_id' => $testId], 'Результаты тестирования кандидата не обнаружены');
        $projectUser     = $projectUserTest->getProjectUser();

        $returnUrl = $projectUser->html()->getUrlToViewUsersTests();

        if (\Yii::$app->request->isPost) {
            $projectUserTest->removeAnswers();
            flash_success('Результат теста сброшены');
            return $this->redirect($returnUrl);
        }

        $test      = $projectUserTest->getTest()->html()->getName();
        $candidate = $projectUserTest->getUser()->html()->getLastFirstName();

        $text = sprintf(
            'Вы действительно хотите <strong class=\'text-danger\'>сбросить</strong> результаты %s <strong>«%s»</strong> кандидата <strong>%s</strong>?',
            (($projectUserTest->getTest()->isSurvey()) ? 'опроса' : 'теста'),
            $test, $candidate
        );

        $renderData = [
            'text'       => $text,
            'cancelUrl'  => $returnUrl,
            'answerText' => 'Сбросить',
        ];

        if ($this->getRequest()->getIsAjax()) {
            $renderData['isModal'] = true;
            return $this->renderPartial('/partials/_remove', $renderData);
        } else {
            return $this->render('/partials/_remove', $renderData);
        }
    }

    /**
     * Удаление кандидата из проекта
     *
     * @param int $projectId
     * @param int $userId
     *
     * @return string|\yii\web\Response
     * @throws
     */
    public function actionRemoveCandidate($projectId, $userId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_USERS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа к результам кандидатам данного проекта ');
        }
        $projectUser = $this->findOrFail(ProjectUser::class, ['project_id' => $projectId, 'user_id' => $userId], 'Кандидат не обнаружен');

        $returnUrl = $projectUser->getProject()->html()->getUrlToCandidates();

        if (\Yii::$app->request->isPost) {
            $projectUser->delete();
            flash_success('Кандидат успешно удалён');
            return $this->redirect($returnUrl);
        }

        $projectName   = $project->html()->getName();
        $candidateName = $projectUser->getUser()->html()->getLastFirstName();

        $renderData = [
            'text'       => "Вы действительно хотите <strong class='text-danger'>удалить</strong> кандидата <strong>{$candidateName}</strong> из проекта «<strong>{$projectName}</strong>» ?",
            'cancelUrl'  => $returnUrl,
            'answerText' => 'Удалить',
        ];

        if ($this->getRequest()->getIsAjax()) {
            $renderData['isModal'] = true;
            return $this->renderPartial('/partials/_remove', $renderData);
        } else {
            return $this->render('/partials/_remove', $renderData);
        }
    }

    /**
     * @param mixed $projectId
     * @param mixed $userId
     * @param mixed $testId
     *
     * @return string
     * @throws
     */
    public function actionViewUsersTestsReports($projectId, $userId, $testId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет доступа к отчётам по кандидату данного проекта ');
        }

        /** @var ProjectUserTest $projectUserTest */
        $projectUserTest = $this->findOrFail(ProjectUserTest::class, ['project_id' => $projectId, 'user_id' => $userId, 'test_id' => $testId], 'Результаты тестирования кандидата не обнаружены');
        $projectUser     = $projectUserTest->getProjectUser();

        if (!$projectUserTest->isStatusFinished()) {
            return $this->redirect($projectUser->html()->getUrlToViewUsersTests());
        }

        return $this->renderContent(Columns::widget(
            [
                'leftSideWidget' => [
                    'class'  => ClientsBar::class,
                    'client' => $project->getClient(),
                ],
                'centerWidgets'  => [
                    [
                        'class'     => ProjectsList::class,
                        'title'     => 'Проект',
                        'subtitle'  => $project->getClientHtml()->linkToView('клиента {link}'),
                        'isOneItem' => true,
                        'id'        => $projectId,
                        'returnUrl' => $this->getRequestUrl(),
                    ],
                    [
                        'class'     => ProjectsUsersList::class,
                        'project'   => $project,
                        'title'     => 'Кандидат',
                        'subtitle'  => $project->html()->linkToView('в проекте «{link}» ' . $project->getClientHtml()->linkToView('клиента {link}')),
                        'emptyText' => 'Не найдено ни одного кандиадата',
                        'id'        => $projectUser->getId(),
                        'isOneItem' => true,
                        'returnUrl' => $this->getRequestUrl(),
                    ],
                    [
                        'class'       => ProjectsUsersTestsList::class,
                        'projectUser' => $projectUser,
                        'title'       => 'Тест',
                        'subtitle'    => $projectUser->html()->linkToView('кандидата {link}'),
                        'emptyText'   => 'На кандидата не назначено ни одного теста',
                        'id'          => $projectUserTest->getId(),
                        'isOneItem'   => true,
                        'returnUrl'   => $this->getRequestUrl(),
                    ],
                    [
                        //'class'           => ProjectUserReportListType::getClassByType($projectUserTest->getTest()->getTypeReport()),
                        'class'           => ProjectsUsersProfessionalTestsReportsList::class,
                        'projectUserTest' => $projectUserTest,
                        'title'           => 'Отчеты',
                        'subtitle'        => $projectUserTest->getTest()->html()->linkToView('на тест «{link}»'),
                        'returnUrl'       => $this->getRequestUrl(),
                    ],
                ],
            ]
        ));
    }

    /**
     * Кнопка "запуск" проекта
     *
     * @param mixed $projectId
     *
     * @return string|\yii\web\Response
     * @throws
     */
    public function actionStart($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа к данному проекту');
        }

        $returnUrl = $this->getReturnUrl($project->html()->getUrlToCandidates());

        if (\Yii::$app->request->isPost) {
            if ($project->start()) {
                flash_success($project->html()->linkToView('Проект «{link}» запущен'));
            } else {
                flash_error($project->html()->linkToView('Не удалось запустить проект «{link}»'));
            }

            return $this->redirect($returnUrl);
        }

        $projectName = $project->html()->getName();

        return $this->renderConfirmAsk(
            "Вы действительно хотите <strong class='text-success'>запустить проект</strong> <strong>«{$projectName}»</strong>, чтобы начать тестировать кандидатов?",
            $returnUrl,
            'Запустить'
        );
    }

    /**
     * Кнопка "остановить" проекта
     *
     * @param mixed $projectId
     *
     * @return string|\yii\web\Response
     * @throws
     */
    public function actionStop($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_STORE)) {
            throw new ForbiddenHttpException('У вас нет доступа к данному проекту');
        }

        $returnUrl = $this->getReturnUrl($project->html()->getUrlToCandidates());

        if (\Yii::$app->request->isPost) {
            if ($project->stop()) {
                flash_success($project->html()->linkToView('Проект «{link}» остановлен'));
            } else {
                flash_error($project->html()->linkToView('Не удалось остановить проект «{link}»'));
            }

            return $this->redirect($returnUrl);
        }

        $projectName = $project->html()->getName();

        return $this->renderConfirmRemove(
            "Вы действительно хотите <strong class='text-danger'>остановить проект</strong> <strong>«{$projectName}»</strong>?",
            $returnUrl,
            'Остановить'
        );
    }

    /**
     * Получить ссылку на открытый проект
     *
     * @param int $projectId
     *
     * @return string
     * @throws
     */
    public function actionLink($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет доступа к данному проекту');
        }

        $link      = $project->html()->getUrlToRegisterInProject();
        $returnUrl = $this->getReturnUrl($project->html()->getUrlToCandidates());

        $text = sprintf(
            "Ссылка <small>на проект «%s» клиента %s</small>",
            $project->html()->getLinkToView(),
            $project->getClientHtml()->getLinkToView()
        );

        return $this->renderLink($text, $link, $returnUrl);
    }

    /**
     * Получить ссылку на инструмент тестирования для кандидата
     *
     * @param int $projectId
     * @param int $userId
     * @param int $testId
     *
     * @return string
     * @throws
     */
    public function actionLinkToInstrument($projectId, $userId, $testId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет доступа к данному проекту');
        }

        $test = $this->findOrFail(Test::class, $testId, 'Инструмент не обнаружен');

        /** @var ProjectUserTest $projectUserTest */
        $projectUserTest = ProjectUserTest::find()->where([
            'project_id' => $projectId,
            'user_id'    => $userId,
            'test_id'    => $testId,
        ])->one();
        if (empty($projectUserTest)) {
            throw new NotFoundHttpException('Указанного кандидата в данном тесте проекта не обнаружено');
        }

        $link      = $projectUserTest->html()->getUrlToPlayer(true);
        $returnUrl = $this->getReturnUrl($project->html()->getUrlToCandidates());

        $text = sprintf(
            "Ссылка <small>на прохождение «%s» в проекте %s</small>",
            $test->html()->getLinkToView(),
            $project->html()->getLinkToView()
        );

        return $this->renderLink($text, $link, $returnUrl);
    }

    /**
     * Раздел с отчётом безопасности по инструменту тестирования в проекте у кандидата
     *
     * @param int $projectId
     * @param int $userId
     * @param int $testId
     *
     * @return string
     * @throws
     */
    public function actionSecurityCheck($projectId, $userId, $testId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет доступа к данному проекту');
        }

        $test = $this->findOrFail(Test::class, $testId, 'Инструмент не обнаружен');

        /** @var ProjectUserTest $projectUserTest */
        $projectUserTest = ProjectUserTest::find()->where([
            'project_id' => $projectId,
            'user_id'    => $userId,
            'test_id'    => $testId,
        ])->one();
        if (empty($projectUserTest)) {
            throw new NotFoundHttpException('Указанного кандидата в данном тесте проекта не обнаружено');
        }
        $candidate = $this->findOrFail(Candidate::class, $userId, 'Кандидат не обнаружен');

        $returnUrl = $this->getReturnUrl($project->html()->getUrlToCandidates());
        $sessions = $projectUserTest->calcSecurityCheck();

        $data = compact(
            'project',
            'candidate',
            'sessions',
            'returnUrl'
        );
        if ($this->getRequest()->getIsAjax()) {
            $data['isModal'] = true;
            return $this->renderPartial('security', $data);
        } else {
            return $this->render('security', $data);
        }
    }

    /**
     * @param int $projectId
     * @param int $userId
     *
     * @return string
     * @throws
     */
    public function actionSendAccessLetter($projectId, $userId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_USERS_SEND_ACCESS_LETTER)) {
            throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
        }

        $returnUrl = $this->getReturnUrl($project->html()->getUrlToCandidates());

        if (!$project->isStatusStarted()) {
            return $this->redirect($returnUrl);
        }
        $projectUser = $this->findProjectUser(['project_id' => $projectId, 'user_id' => $userId]);

        if ($this->isPostRequest()) {
            $projectUser->resendAccessLetter();
            flash_success('Уведомление отправлено кандидату');
            return $this->redirect($returnUrl);
        }

        $userName = $projectUser->getUser()->html()->getLastFirstName();

        return $this->render('/partials/_ask', [
            'text'       => "Вы действительно хотите <strong class='text-success'>отправить уведомление</strong> кандидату <strong>{$userName}</strong>?",
            'cancelUrl'  => $returnUrl,
            'answerText' => 'Отправить',
        ]);
    }

    /**
     * Запуск теста у кандидата
     *
     * @param $projectId
     * @param $userId
     * @param $testId
     *
     * @return string|\yii\web\Response
     * @throws
     */
    public function actionStartTest($projectId, $userId, $testId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
        }

        $projectUserTest = $this->findProjectUserTest(['project_id' => $projectId, 'user_id' => $userId, 'test_id' => $testId]);
        $projectUser     = $projectUserTest->getProjectUser();

        /** @var Test $projectTest */
        $projectTest = $projectUserTest->getTest();
        $returnUrl   = $this->getReturnUrl($projectUser->html()->getUrlToViewUsersTests());

        if (\Yii::$app->request->isPost) {
            if ($projectUserTest->start(true)) {
                flash_success($projectTest->html()->linkToView('Тест «{link}» запущен'));
            } else {
                flash_error($projectTest->html()->linkToView('Не удалось запустить тест «{link}»'));
            }

            return $this->redirect($returnUrl);
        }

        $testName = $projectTest->html()->getName();
        $text     = sprintf(
            'Вы действительно хотите <strong class=\'text-success\'>запустить</strong> %s <strong>«%s»</strong>?',
            (($projectUserTest->getTest()->isSurvey()) ? 'опрос' : 'тест'),
            $testName
        );

        return $this->renderConfirmAsk(
            $text,
            $returnUrl
        );
    }

    /**
     * Кнопка "завершить тест" кандидата
     *
     * @param $projectId
     * @param $userId
     * @param $testId
     *
     * @return string|\yii\web\Response
     * @throws
     */
    public function actionFinishTest($projectId, $userId, $testId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
        }

        $projectUserTest = $this->findProjectUserTest(['project_id' => $projectId, 'user_id' => $userId, 'test_id' => $testId]);
        $projectUser     = $projectUserTest->getProjectUser();

        /** @var Test $projectTest */
        $projectTest = $projectUserTest->getTest();
        $returnUrl   = $this->getReturnUrl($projectUser->html()->getUrlToViewUsersTests());

        if (\Yii::$app->request->isPost) {
            if ($projectUserTest->finish()) {
                flash_success($projectTest->html()->linkToView('Тест «{link}» завершён'));
            } else {
                flash_error($projectTest->html()->linkToView('Не удалось завершить тест «{link}»'));
            }

            return $this->redirect($returnUrl);
        }

        $testName = $projectTest->html()->getName();

        return $this->renderConfirmAsk(
            "Вы действительно хотите <strong class='text-success'>завершить</strong> тест <strong>«{$testName}»</strong>?",
            $returnUrl
        );
    }

    /**
     * @param Project $project
     * @param string $message
     *
     * @return string|\yii\web\Response
     */
    public function saveProject(Project $project, $message)
    {
        return $this->saveAndRedirect($project, ProjectForm::class, function () use ($project) {
            return $project->html()->getUrlToCandidates();
        }, $message);
    }

    /**
     * @param \Closure $recordCreator
     * @param \Closure $formCreator
     * @param \Closure $urlToRedirect
     * @param string $message
     *
     * @return string|\yii\web\Response
     */
    public function multiSaveCandidate($recordCreator, $formCreator, $urlToRedirect, $message)
    {
        return $this->multiSaveAndRedirect(CandidateMultiModel::class, $recordCreator, $formCreator, $urlToRedirect, $message);
    }

    /**
     * Страница с экспортами данных по проекту
     * -- общий экспорт "сырых" данных (csv, )
     * -- экспорт по указанным нормативным группам (при условии что все группы указаны в обозначенных тестах)
     *
     * @param $projectId
     *
     * @return string
     * @throws
     */
    public function actionExport($projectId)
    {
        /** @var Project $project */
        $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
        if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
            throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
        }

        $returnUrl = $this->getReturnUrl($project->html()->getUrlToCandidates());

        $testsForExport           = $project->getTests();
        $normativeGroupsForExport = []; // допускаем только те группы, которые есть во всех тестах!
        $nGroupsByTests           = []; // группы c сортировкой по тестам
        $intersectNGroups         = []; // пересекающиеся N-группы
        $adaptiveTests            = []; // адаптивные тесты

        // ищем пересечения по нормативным группам
        if ($testsForExport) {

            $testsForExport->map(function (TestInstrument $test) use (& $nGroupsByTests, & $normativeGroupsForExport, & $adaptiveTests) {
                $nGroupsByTests[$test->getId()] = $test->getTestNormativeGroups()->select(
                    function (TestNormativeGroup $el) use (& $normativeGroupsForExport) {
                        if (!isset($normativeGroupsForExport[$el->getNGroupId()])) {
                            $normativeGroupsForExport[$el->getNGroupId()] = $el;
                        }
                        return $el;
                    },
                    function (TestNormativeGroup $el) {
                        return $el->getNGroupId();
                    }
                );

                if ($test->getType() == TestType::ADAPTIVE) {
                    $adaptiveTests[$test->getId()] = $test;
                }
            });

            if (count($nGroupsByTests) > 1) { // если в проекте больше одного теста
                $intersectNGroups = call_user_func_array(
                    'array_intersect',
                    array_map(function ($el) {
                        return array_keys($el);
                    }, $nGroupsByTests)
                );
            } else {
                $intersectNGroups = array_keys(array_pop($nGroupsByTests));
            }

        }

        $data = compact(
            'project',
            'isModal',
            'testsForExport',
            'normativeGroupsForExport',
            'intersectNGroups',
            'adaptiveTests'
        );

        if ($this->getRequest()->getIsAjax()) {
            //$isModal = true;
            $data['isModal'] = true;
            return $this->renderPartial('export', $data);
        } else {
            return $this->render('export', $data);
        }
    }

    /**
     * Экспорт данных по указанной нормативной группе
     * Если приходит POST-запрос, то также смотрится userIds
     *
     * @deprecated TODO
     *
     * @param int $projectId
     * @param int $normativeGroupId
     *
     * @return int
     * @throws HttpException
     */
    public function actionExportByNGroup($projectId, $normativeGroupId)
    {
        try {
            /** @var Project $project */
            $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
            if (!$project->getClient()->hasAccess(\Yii::$app->getUser()->identity, Permission::PROJECTS_VIEW)) {
                throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
            }

            $nGroup = $this->findNormativeGroup($normativeGroupId);
            $format = \Yii::$app->request->get('format', 'xlsx');
            if (!in_array($format, Export::$allowedFormats)) {
                throw new \Exception('Указанный формат не поддерживается');
            }

            $fileName = sprintf('%s_by_ngroup_%s.%s',
                Inflector::slug($project->getName(), '_'),
                Inflector::slug($nGroup->getName(), '_'),
                $format
            );
            $filePath = App::assetManager()->basePath . '/' . $fileName;
            if (is_file($filePath)) {
                unlink($filePath);
            }

            $userIds = [];
            if (\Yii::$app->request->isPost) {
                $userIds = \Yii::$app->request->post('userIds', []);
            }

            $command = sprintf(
                'cd %s && php ./yii projects/export-by-n-group %d %d %s "%s" %s',
                \Yii::$app->getBasePath(),
                $projectId,
                $normativeGroupId,
                $filePath,
                implode(',', $userIds),
                $format
            );
            \Yii::trace($command, __METHOD__);

            $echo = null;
            passthru($command, $echo);

            if (is_file($filePath)) {
                header('Content-Description: File Transfer');
                header(sprintf('Content-Disposition: attachment; filename="%s"', $fileName));
                header('Content-Type: application/octet-stream');
                header('Content-Transfer-Encoding: binary');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Expires: 0');
                header(sprintf('X-Accel-Redirect:%s', \Yii::$app->getAssetManager()->baseUrl . '/' . $fileName));

                return readfile($filePath);
            }
            throw new Exception(sprintf('File not found: %s', $filePath));

        } catch (\Exception $e) {
            \Yii::error([
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            ], __METHOD__);
            throw new HttpException(404, $e->getMessage());
        }
    }

    /**
     * Экспорт кодифицированныз ответов и бальных ответов
     *
     * @param int $projectId
     *
     * @return int
     * @throws HttpException
     */
    public function actionExportAnswers($projectId)
    {
        try {
            /** @var Project $project */
            $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
            if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
                throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
            }

            $allowedTypes = ['codes', 'values'];
            $type         = \Yii::$app->request->post('type', 'codes');
            if (!in_array($type, $allowedTypes)) {
                throw new BadRequestHttpException('Указанный тип экспорта не обнаружен');
            }

            // сырые балы могу выгружать только супер-админы
            if ($type == 'values' && $this->getUser()->getRoleAlias() != Role::ADMINISTRATOR) {
                throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
            }

            $format = \Yii::$app->request->post('format', 'xlsx');
            if (!in_array($format, Export::$allowedFormats)) {
                throw new \Exception('Указанный формат не поддерживается');
            }

            $fileName = sprintf('%s_answers_%s.%s',
                Inflector::slug($project->getName(), '_'), $type, $format
            );
            $filePath = App::assetManager()->basePath . '/' . $fileName;
            if (is_file($filePath)) {
                unlink($filePath);
            }

            $command = sprintf(
                'cd %s && php ./yii projects/export-answers-%s %d %s "%s" "%s" %s "%s"',
                \Yii::$app->getBasePath(),
                $type,
                $project->getId(),
                $filePath,
                \Yii::$app->request->post('userIds', ''),
                \Yii::$app->request->post('testIds', ''),
                $format,
                implode(',', [
                    \Yii::$app->request->post('formInclude', 0),
                    \Yii::$app->request->post('commonInclude', 0)
                ])
            );
            \Yii::trace($command, __METHOD__);

            $echo = null;
            passthru($command, $echo);

            if (is_file($filePath)) {
                header('Content-Description: File Transfer');
                header(sprintf('Content-Disposition: attachment; filename="%s"', $fileName));
                header('Content-Type: application/octet-stream');
                header('Content-Transfer-Encoding: binary');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Expires: 0');
                header(sprintf('X-Accel-Redirect:%s', \Yii::$app->getAssetManager()->baseUrl . '/' . $fileName));

                return readfile($filePath);
            }
            throw new Exception(sprintf('File not found: %s', $filePath));

        } catch (\Exception $e) {
            \Yii::error([
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            ], __METHOD__);
            throw new HttpException(404, $e->getMessage());
        }
    }

    /**
     * Экспорт ответов по шкалам
     *
     * @param int $projectId
     *
     * @return int
     * @throws HttpException
     */
    public function actionExportTags($projectId)
    {
        try {
            /** @var Project $project */
            $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
            if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
                throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
            }

            $format = \Yii::$app->request->post('format', 'xlsx');
            if (!in_array($format, Export::$allowedFormats)) {
                throw new \Exception('Указанный формат не поддерживается');
            }

            $fileName = sprintf('%s_tags.%s',
                Inflector::slug($project->getName(), '_'), $format
            );
            $filePath = App::assetManager()->basePath . '/' . $fileName;
            if (is_file($filePath)) {
                unlink($filePath);
            }

            $command = sprintf(
                'cd %s && php ./yii projects/export-tags %d %s "%s" "%s" %s "%s"',
                \Yii::$app->getBasePath(),
                $project->getId(),
                $filePath,
                \Yii::$app->request->post('userIds', ''),
                \Yii::$app->request->post('testIds', ''),
                $format,
                implode(',', [
                    \Yii::$app->request->post('formInclude', 0),
                    \Yii::$app->request->post('commonInclude', 0)
                ])
            );
            \Yii::trace($command, __METHOD__);

            $echo = null;
            passthru($command, $echo);

            if (is_file($filePath)) {
                header('Content-Description: File Transfer');
                header(sprintf('Content-Disposition: attachment; filename="%s"', $fileName));
                header('Content-Type: application/octet-stream');
                header('Content-Transfer-Encoding: binary');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Expires: 0');
                header(sprintf('X-Accel-Redirect:%s', \Yii::$app->getAssetManager()->baseUrl . '/' . $fileName));

                return readfile($filePath);
            }
            throw new Exception(sprintf('File not found: %s', $filePath));

        } catch (\Exception $e) {
            \Yii::error([
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            ], __METHOD__);
            throw new HttpException(404, $e->getMessage());
        }
    }

    /**
     * Экспорт ответов по нормативным группам
     *
     * @param int $projectId
     *
     * @return int
     * @throws HttpException
     */
    public function actionExportNGroups($projectId)
    {
        try {
            /** @var Project $project */
            $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
            if ($this->getUser()->getRoleAlias() != Role::ADMINISTRATOR) {
                throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
            }

//            if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
//                throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
//            }

            $nGroupID = \Yii::$app->request->post('nGroupID');
            if (empty($nGroupID)) {
                throw new BadRequestHttpException('Нормативная группа не указана');
            }
            /** @var NormativeGroup $nGroup */
            $nGroup = $this->findOrFail(NormativeGroup::class, $nGroupID, 'Нормативная группа не обнаружена');


            $format = \Yii::$app->request->post('format', 'xlsx');
            if (!in_array($format, Export::$allowedFormats)) {
                throw new \Exception('Указанный формат не поддерживается');
            }

            $fileName = sprintf('%s_ngroups.%s',
                Inflector::slug($project->getName(), '_'), $format
            );
            $filePath = App::assetManager()->basePath . '/' . $fileName;
            if (is_file($filePath)) {
                unlink($filePath);
            }

            $command = sprintf(
                'cd %s && php ./yii projects/export-n-groups %d %d "%s" "%s" "%s" %s "%s"',
                \Yii::$app->getBasePath(),
                $project->getId(),
                $nGroup->getId(),
                $filePath,
                \Yii::$app->request->post('userIds', ''),
                \Yii::$app->request->post('testIds', ''),
                $format,
                implode(',', [
                    \Yii::$app->request->post('formInclude', 0),
                    \Yii::$app->request->post('commonInclude', 0)
                ])
            );
            \Yii::trace($command, __METHOD__);

            $echo = null;
            passthru($command, $echo);

            if (is_file($filePath)) {
                header('Content-Description: File Transfer');
                header(sprintf('Content-Disposition: attachment; filename="%s"', $fileName));
                header('Content-Type: application/octet-stream');
                header('Content-Transfer-Encoding: binary');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Expires: 0');
                header(sprintf('X-Accel-Redirect:%s', \Yii::$app->getAssetManager()->baseUrl . '/' . $fileName));

                return readfile($filePath);
            }
            throw new Exception(sprintf('File not found: %s', $filePath));

        } catch (\Exception $e) {
            \Yii::error([
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            ], __METHOD__);
            throw new HttpException(404, $e->getMessage());
        }
    }


    /**
     * Экспорт ответов по уровнямм способностей
     *
     * @param int $projectId
     *
     * @return int
     * @throws HttpException
     */
    public function actionExportThetas($projectId)
    {
        try {
            /** @var Project $project */
            $project = $this->findOrFail(Project::class, $projectId, 'Проект не обнаружен');
            if (!$project->getClient()->hasAccess($this->getUser(), Permission::PROJECTS_VIEW)) {
                throw new ForbiddenHttpException('У вас нет возможности совершать данную операцию');
            }

            $format = \Yii::$app->request->post('format', 'xlsx');
            if (!in_array($format, Export::$allowedFormats)) {
                throw new \Exception('Указанный формат не поддерживается');
            }

            $fileName = sprintf('%s_thetas.%s',
                Inflector::slug($project->getName(), '_'), $format
            );
            $filePath = App::assetManager()->basePath . '/' . $fileName;
            if (is_file($filePath)) {
                unlink($filePath);
            }

            $command = sprintf(
                'cd %s && php ./yii projects/export-thetas %d %s "%s" "%s" %s "%s"',
                \Yii::$app->getBasePath(),
                $project->getId(),
                $filePath,
                \Yii::$app->request->post('userIds', ''),
                \Yii::$app->request->post('testIds', ''),
                $format,
                implode(',', [
                    \Yii::$app->request->post('formInclude', 0),
                    \Yii::$app->request->post('commonInclude', 0)
                ])
            );
            \Yii::trace($command, __METHOD__);

            $echo = null;
            passthru($command, $echo);

            if (is_file($filePath)) {
                header('Content-Description: File Transfer');
                header(sprintf('Content-Disposition: attachment; filename="%s"', $fileName));
                header('Content-Type: application/octet-stream');
                header('Content-Transfer-Encoding: binary');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Expires: 0');
                header(sprintf('X-Accel-Redirect:%s', \Yii::$app->getAssetManager()->baseUrl . '/' . $fileName));

                return readfile($filePath);
            }
            throw new Exception(sprintf('File not found: %s', $filePath));

        } catch (\Exception $e) {
            \Yii::error([
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            ], __METHOD__);
            throw new HttpException(404, $e->getMessage());
        }
    }

}