<?php

namespace App\Controllers;

use App\Lib\Web\Controller;
use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
use App\Widgets\Columns;
use App\Widgets\Lists\UsersList;
use App\Workers\MailerWorker;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;

/**
 * @package App\Controllers
 * Для работы с пользователем
 */
class UsersController extends Controller
{
    public $layout = 'admin';

    /**
     * @return string
     * @throws \Exception
     */
    public function actionProfile($userId=false)
    {

        if ($userId) {

            if (!$this->getUser()->hasPermission(Permission::USERS_VIEW)) {
                throw new ForbiddenHttpException('Доступ к данному разделу закрыт');
            }

            $curUser = User::findOne($userId);
            if(empty($curUser)) {
                throw new HttpException(404, 'Пользователь не найден');
            }

            $this->layout = 'admin';

        } else {
            $curUser = $this->getUser();
            $this->layout = 'user';
        }

        $projectsClientId = $curUser->id;

        $feedbackSent = false;

        // TODO добвить форму с валидацией
        if(\Yii::$app->request->isPost) {

            $feedback = \Yii::$app->request->post('feedback');

            if(!empty($feedback)) {
                $feedbackSent = true;

                $mailer = new MailerWorker();

                $mailer->sendAdminFeedback($curUser, $feedback);
            }
        }

        $projects = Project::find()
            ->joinWith('projectsUsersTestsRelation.testRelation')
            ->where([
                'projects_users_tests.user_id' => $projectsClientId
            ])
            ->orderBy('projects_users_tests.id DESC')
            ->all();

        return $this->render('/user/profile', [
            'projects'     => $projects,
            'user'         => $curUser,
            'feedbackSent' => $feedbackSent,
            'userId'       => $userId
        ]);
    }

    /**
     * Список пользователей
     *
     * @return string
     * @throws
     */
    public function actionList()
    {

        if (!$this->getUser()->hasPermission(Permission::USERS_VIEW)) {
            throw new ForbiddenHttpException('Доступ к данному разделу закрыт');
        }

        return $this->renderContent(Columns::widget([
            'centerWidget' => [
                'class'     => UsersList::class,
                'title'     => 'Все пользователи',
                'emptyText' => 'Не найдено ни одного пользователя',
                'returnUrl' => $this->getRequestUrl(),

                'searchQuery' => (\Yii::$app->request->get('q')) ? trim(\Yii::$app->request->get('q')) : null,
            ],
        ]));
    }
}