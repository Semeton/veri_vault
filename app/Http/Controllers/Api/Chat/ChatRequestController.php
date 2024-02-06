<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\Chat;

use Exception;
use App\Models\User;
use App\Models\ChatRequest;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Enums\HTTPResponseEnum;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Services\ChatRequestService;
use Illuminate\Support\Facades\Auth;

class ChatRequestController extends Controller
{
    public function __construct(public User $user, public ChatRequestService $chatRequestService)
    {

    }

    public function index()
    {
        try{
            $this->user = Auth::user();
            
            $sentChatRequests = $this->user->sentChatRequests;
            foreach($sentChatRequests as $item){
                $item->recipient_email = $item->recipient()->get();
            }
            $receivedChatRequests = $this->user->receivedChatRequests;
            foreach($receivedChatRequests as $item){
                $item->sender_email = $item->sender()->get();
            }

            return response()->json([
                'sent' => $sentChatRequests,
                'received' => $receivedChatRequests,
            ], HTTPResponseEnum::OK);
        } catch (Exception $e){
            return response()->json([
                'error' => 'Exception',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function create(Request $request): JsonResponse
    {
        try{
            $data = $request->validate([
                'recipient_email' => 'required|email|exists:users,email',
            ]);

            $this->user = Auth::user();
            $data['uuid'] = Str::uuid();

            if($this->user->email === $data['recipient_email']){
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'You cannot send a chat request to yourself'
                ], HTTPResponseEnum::FORBIDDEN);
            }

            if(Chat::where('sender_id', $this->user->id)->where('recipient_id', User::where('email', $data['recipient_email'])->first()->id)->count() > 0){
                return response()->json([
                    'error' => 'Invalid',
                    'message' => 'An active chat is already established between you and this user'
                ], HTTPResponseEnum::BAD_REQUEST);
            }

            $requestExists = $this->user->sentChatRequests->where('recipient_email', $data['recipient_email']);
            if($requestExists->count() > 0){
                if($requestExists->value('status') === 1){
                    return response()->json([
                        'error' => 'Invalid',
                        'message' => 'An active chat is already established between you and this user'
                    ], HTTPResponseEnum::BAD_REQUEST);
                }
                if($requestExists->value('status') === 3){
                    return response()->json([
                        'error' => 'Invalid',
                        'message' => 'The recipient has blocked you from sending request'
                    ], HTTPResponseEnum::BAD_REQUEST);
                }
                return response()->json([
                    'error' => 'DuplicationError',
                    'message' => 'You have already sent a request to this user'
                ], HTTPResponseEnum::BAD_REQUEST);
            }

            $createRequest = $this->user->sentChatRequests()->create($data);

            if(!$createRequest){
                return response()->json([HTTPResponseEnum::getBadRequestMessage()], HTTPResponseEnum::BAD_REQUEST);
            }

            return response()->json($createRequest, HTTPResponseEnum::CREATED);
        } catch (Exception $e){
            return response()->json([
                'error' => 'Exception',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function acceptRequest(string $uuid)
    {
        try{
            $chatRequest = ChatRequest::whereUuid($uuid)->firstOrFail();
            if($chatRequest){
                if($chatRequest->sender_email === Auth::user()->email){
                    return response()->json([
                        'error' => 'Forbidden',
                        'message' => 'You cannot accept a chat request sent out by you'
                    ], HTTPResponseEnum::FORBIDDEN);
                }
                if($this->chatRequestService->createChat($chatRequest)){
                    $chatRequest->update([ 'status' => 1 ]);
                    return response()->json([
                        'message' => 'Request accepted and chat created successfully'
                    ], HTTPResponseEnum::CREATED);
                }else{
                    return response()->json(HTTPResponseEnum::getBadRequestMessage(), HTTPResponseEnum::BAD_REQUEST);
                }
            }
        } catch (Exception $e){
            return response()->json([
                'error' => 'Exception',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function rejectRequest(string $uuid)
    {
        try{
            $chatRequest = ChatRequest::findOrFail($uuid);
            
            if($chatRequest->update([ 'status' => 2 ])){
                return response()->json(['message' =>'Request rejected'], HTTPResponseEnum::OK);
            } else {
                return response()->json(HTTPResponseEnum::getBadRequestMessage(), HTTPResponseEnum::BAD_REQUEST);
            }

        } catch (Exception $e){
            return response()->json([
                'error' => 'Exception',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function blockUserRequest(string $uuid)
    {
        try{
            $chatRequest = ChatRequest::findOrFail($uuid);
            
            if($chatRequest->update([ 'status' => 3 ])){
                return response()->json(['message' =>'Request rejected'], HTTPResponseEnum::OK);
            } else {
                return response()->json(HTTPResponseEnum::getBadRequestMessage(), HTTPResponseEnum::BAD_REQUEST);
            }

        } catch (Exception $e){
            return response()->json([
                'error' => 'Exception',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function delete(string $uuid)
    {
        try{
            $chatRequest = ChatRequest::where('uuid', $uuid)->firstOrFail();
            $chatRequest->delete();
            return response()->json(['message'=>"Chat request deleted"]);
        } catch (Exception $e){
            return response()->json([
                'error' => 'Exception',
                'message' => $e->getMessage(),
            ]);
        }
    }
}