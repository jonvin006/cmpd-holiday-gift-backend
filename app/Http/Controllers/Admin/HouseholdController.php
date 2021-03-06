<?php

namespace App\Http\Controllers\Admin;

use App\Base\Controllers\AdminController;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\HouseholdRequest;
use App\Household;
use Auth;
use Mail;
use Laracasts\Flash\Flash;

class HouseholdController extends AdminController
{

    /**
     * Display a listing of the users.
     */
    public function index()
    {
        return view('admin.households.index');
    }

    /**
     * Store a newly created user in storage
     */
    public function store(HouseholdRequest $request)
    {
        if(Auth::user()->max_nominations_reached)
        {
            return view("admin.households.error.maxnominations");
        }
        else
        {
            $request['nominator_user_id'] = Auth::user()->id;
            $id = $this->createFlashParentRedirect(Household::class, $request);
            $this->upsertAll(["Child" => $request['child'],
                            "HouseholdAddress"  => $request['address'],
                            "HouseholdPhone"  => $request['phone']], "household_id", $id);
            return $this->redirectRoutePath("index");
        }
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $household = Household::where('id','=',$id)->with("child", "address", "phone")->first();
        return $this->viewPath("show", $household);
    }

    public function delete($id)
    {
        if(!\Auth::user()->hasRole("admin")) {
            return [ "ok" => false, "error" => "Unauthorized" ];
        }else{
            Household::findOrFail($id)->delete();
            return [ "ok" => true ];
        }
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit($id)
    {
        $household = Household::findOrFail($id);
        return $this->viewPath("edit", $household);
    }

    /**
     * Update the specified user in storage.
     */
    public function update($id, HouseholdRequest $request)
    {
        $household = Household::findOrFail($id);
		    $this->upsertAll(["Child" => $request['child'],
						"HouseholdAddress"  => $request['address'],
						"HouseholdPhone"  => $request['phone']], "household_id", $household['id']);
        return $this->saveFlashRedirect($household, $request);
    }

    public function create() {
        if(Auth::user()->max_nominations_reached)
        {
            return view("admin.households.error.maxnominations");
        }
        else
        {
            return view($this->viewPath("create"));
        }
    }

    public function search(Request $request)
    {
        $search = trim ($request->input ("search")["value"] ?: "", " ,");
        $start = $request->input ("start") ?: 0;
        $length = $request->input ("length") ?: 25;
        $columns = $request->input ("columns");
        $order = $request->input ("order");

        $households =  Household::query()
            ->where ("household.name_last", "LIKE", "$search%")
            ->orWhere ("household.email", "LIKE", "%$search%");

        switch($order[0]['column']){
        case "1": // Head of Household
            $households = $households
                ->orderBy ("name_last", $order[0]["dir"])
                ->orderBy ("name_first", $order[0]["dir"]);
            break;
        case "3": // Nominated by
            $households = $households
                ->join('users', 'users.id', '=', 'household.nominator_user_id')
                ->orderBy('users.name_last', $order[0]["dir"])
                ->orderBy('users.name_first', $order[0]["dir"])
                ->select('household.*');
            break;
        }

        $count = $households->count ();

        $households = $households
            ->take ($length)
            ->skip ($start)
            ->get ();

        $res = [];
        foreach ($households as $household) {
          $household->head_of_household_name = "{$household->name_first} {$household->name_last}";
          $household->child_count = count($household->child);
          $household->nominated_by = "<a href='/admin/user/{$household->nominator->id}'>{$household->nominator->name_first} {$household->nominator->name_last}</a>";
          $household->uploaded_form= (count($household->attachment)) ? "Yes" : "--";

          // Household reviewed?
          if ($household->reviewed)
          {
            if ($household->approved)
            {
              $household->review_options = 'Approved';
            }
            else
            {
              // Eventually we will show rejected nominees on a different list
              $household->review_options = 'Rejected';
            }
          }
          else
          {
            if (Auth::user()->hasRole('admin'))
            {
              $household->review_options = '<a onClick="vm.show_review_modal('.$household->id.');" class="btn btn-sm btn-default">Review</a>';
            }
            else
            {
              $household->review_options = 'Not reviewed';
            }
          }
          $res[] = $household;
        }

        $households = $res;



        return $this->dtResponse ($request, $households, $count);
    }

    public function review ($id, Request $request)
    {

      $household = Household::find($id);

      if (!$household)
      {
        return ['ok' => false, 'message' => 'Could not find household.'];
      }

      $approved = $request->input('approved', 0);
      $reason = $request->input('reason' , null);
      $customMessage = $request->input('message', null);

      // If approved?
      switch ($approved)
      {
        // Approved the nomination
        case 1:
          $household->reviewed = 1;
          $household->approved = 1;

          if ($household->save())
          {
            Mail::queue("email.notify_household_accepted", [
              "household" => $household
            ],
              function($message) use($household) {
                $message->from(env("MAIL_FROM_ADDRESS"));
                $message->to($household->nominator->email);
                $message->subject("Your nomination for {$household->name_last} has been approved!");
            });
            return ['ok' => true];
          }
          return ['ok' => $household->save()];
          break;

        // Declined the nomination
        case 0:
          if (!$reason)
          {
            return ['ok' => false, 'message' => 'Must provide a reason for declining.'];
          }

          // Update stuffs...
          $household->reviewed = 1;
          $household->approved = 0;
          $household->reason = $reason;

          if ($household->save())
          {
            if ($customMessage)
            {
              Mail::queue("email.notify_household_rejected", [
                "household" => $household,
                "reason" => $reason,
                "customMessage" => $customMessage
              ],
              function($message) use($household) {
                $message->from(env("MAIL_FROM_ADDRESS"));
                $message->to($household->nominator->email);
                $message->subject("An update regarding your nomination of {$household->name_last}");
              });
            }
            return ['ok' => true];
          }
          else
          {
            return ['ok' => false, 'message' => 'Could not update nomination. Please try again later.'];
          }


          break;
      }
    }

    public function packing_slip_config(Request $request) {
        return view("admin.packing_slip_config", [
                                                  "household_id" => $request->input('household_id'),
                                                  "packing_slip_radio" =>$request->cookie('packing_slip_radio'),
                                                  "packing_slip_phone" => $request->cookie('packing_slip_phone')
                                                  ]);
    }

    public function packing_slip_set_config(Request $request) {
        $radio = $request->input('packing_slip_radio');
        $phone = $request->input('packing_slip_phone');
        $id = $request->input('household_id');
        $url = $id ? '/admin/household/' . $id . '/packing_slip' : '/admin';
        return redirect($url)
            ->cookie("packing_slip_phone", $phone, 60*60*24*120)
            ->cookie("packing_slip_radio", $radio, 60*60*24*120);
    }

    public function packing_slip(Request $request, $id) {
        if(!\Auth::user()->hasRole("admin")) {
            abort(403);
        }
        $radio = $request->cookie('packing_slip_radio');
        $phone = $request->cookie('packing_slip_phone');
        if(!$phone || !$radio){
            return redirect('/admin/packing_slip_config?household_id=' . $id);
        }
        return view("admin.households.packing_slip", [
               "households" => [Household::findOrFail($id)],
               "assistance" => [
                            "phone" => $phone,
                            "radio" => $radio
               ]
        ]);
    }

    public function packing_slips(Request $request) {
        if(!\Auth::user()->hasRole("admin")) {
            abort(403);
        }

        $radio = $request->cookie('packing_slip_radio');
        $phone = $request->cookie('packing_slip_phone');
        if(!$phone || !$radio){
            return redirect('/admin/packing_slip_config');
        }

        $households = Household::query()->where('approved', '=', 1);

        // TODO: limit which packing slips to print
        // $after = $request->input('after');
        // if($after != NULL){
        //     $when = strtotime($after);
        //     if(!$when){
        //         Flash::error('Invalid date or time: ' . $after);
        //         return redirect('/admin');
        //     }
        //     $households = $households->andWhere('updated_at', '>=', date('y-m-d H:i:s', $when));
        // }

        return view("admin.households.packing_slip", [
               "households" => $households->get(),
               "assistance" => [
                            "phone" => $phone,
                            "radio" => $radio
               ]
        ]);

    }
}
