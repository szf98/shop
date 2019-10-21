$info = \app\common\model\Brand::alias('t1')
            ->join('pyg_category t2', 't1.cate_id=t2.id', 'left')
            ->field('t1.*,t2.cate_name')
            ->where('t1.id', 1)
            ->find();
        $this->ok($info);
